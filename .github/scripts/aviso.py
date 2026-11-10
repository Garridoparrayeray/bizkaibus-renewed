#!/usr/bin/env python3
"""Prepara notice.json, el aviso general de Bide+, para el workflow «Aviso a los usuarios».

Uso: python3 .github/scripts/aviso.py <archivo de salida>
Lee lo que escribió quien lanzó el workflow de las variables ACCION, TEXTO_ES, TEXTO_EU, TEXTO_EN, ENLACE, HORAS, HASTA
y NOTIFICAR. Con ACCION=quitar escribe un archivo vacío ({}), que la app ya no pinta. Si algo está mal escrito sale con
un error que dice qué, antes de tocar la release.
"""
import json
import os
import sys
import time
from datetime import datetime
from urllib.parse import urlparse
from zoneinfo import ZoneInfo

MAX_TEXT_LENGTH = 400
MIN_HOURS = 1
MAX_HOURS = 720
MADRID = ZoneInfo('Europe/Madrid')
UNTIL_FORMAT = '%Y-%m-%d %H:%M'


class NoticeError(Exception):
    pass


def web_address(address):
    if address == '':
        return None
    parsed = urlparse(address)
    if parsed.scheme not in ('http', 'https') or parsed.netloc == '' or ' ' in address:
        raise NoticeError('El enlace tiene que empezar por https:// (o http://) y no llevar espacios.')
    return address


def hours_from(value):
    try:
        hours = int(value.strip())
    except ValueError:
        raise NoticeError('Las horas tienen que ser un número entero.')
    if hours < MIN_HOURS or hours > MAX_HOURS:
        raise NoticeError(f'Las horas tienen que estar entre {MIN_HOURS} y {MAX_HOURS}.')
    return hours


# Cuándo caduca: la fecha y hora de Madrid de «Hasta» si se rellena y, si no, las horas desde ahora.
def expiry_from(env, now):
    until = env.get('HASTA', '').strip()
    if until == '':
        return int(now) + hours_from(env.get('HORAS', '36')) * 3600
    try:
        moment = datetime.strptime(until, UNTIL_FORMAT).replace(tzinfo=MADRID)
    except ValueError:
        raise NoticeError('«Hasta» tiene que ser AAAA-MM-DD HH:MM en hora de Madrid, por ejemplo 2026-11-11 16:00.')
    expires = int(moment.timestamp())
    if expires <= now:
        raise NoticeError('«Hasta» ya ha pasado: tiene que ser una fecha y hora futuras.')
    if expires - now > MAX_HOURS * 3600:
        raise NoticeError(f'«Hasta» está a más de {MAX_HOURS} horas: elige una fecha más cercana.')
    return expires


def texts_from(values):
    texts = {}
    for lang, value in values.items():
        text = value.strip()
        if len(text) > MAX_TEXT_LENGTH:
            raise NoticeError(f'El texto en {lang} tiene {len(text)} caracteres y el máximo es {MAX_TEXT_LENGTH}.')
        if text != '':
            texts[lang] = text
    if 'es' not in texts:
        raise NoticeError('Falta el texto en castellano: es obligatorio al publicar.')
    return texts


def build(env, now):
    action = env.get('ACCION', 'publicar').strip()
    if action == 'quitar':
        return {}
    if action != 'publicar':
        raise NoticeError('La acción tiene que ser «publicar» o «quitar».')
    notice = {
        'id': time.strftime('%Y%m%dT%H%M%SZ', time.gmtime(now)),
        'text': texts_from({'es': env.get('TEXTO_ES', ''), 'eu': env.get('TEXTO_EU', ''), 'en': env.get('TEXTO_EN', '')}),
        'expiresAt': expiry_from(env, now),
        'notify': env.get('NOTIFICAR', 'false').strip().lower() == 'true',
    }
    address = web_address(env.get('ENLACE', '').strip())
    if address is not None:
        notice['url'] = address
    return notice


def summary(notice):
    if notice == {}:
        return 'Aviso quitado: la app deja de enseñarlo en un par de minutos.'
    until = datetime.fromtimestamp(notice['expiresAt'], MADRID).strftime('%d/%m/%Y %H:%M')
    lines = [f"Aviso publicado, se muestra hasta el {until} (hora de Madrid): {notice['text']['es']}"]
    if notice['notify']:
        lines.append('Se manda también una notificación a los móviles con los avisos activados, una sola vez.')
    return '\n'.join(lines)


def main():
    if len(sys.argv) != 2:
        print(__doc__)
        return 2
    try:
        notice = build(os.environ, time.time())
    except NoticeError as error:
        print(f'::error::{error}')
        return 1
    with open(sys.argv[1], 'w', encoding='utf-8') as out:
        json.dump(notice, out, ensure_ascii=False)
    print(summary(notice))
    return 0


if __name__ == '__main__':
    sys.exit(main())
