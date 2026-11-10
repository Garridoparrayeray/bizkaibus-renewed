#!/usr/bin/env python3
"""Prepara notice.json, el aviso general de Bide+, para el workflow «Aviso a los usuarios».

Uso: python3 .github/scripts/aviso.py <archivo de salida>
Lee lo que escribió quien lanzó el workflow de las variables ACCION, TEXTO_ES, TEXTO_EU, TEXTO_EN, ENLACE, HORAS y
NOTIFICAR. Con ACCION=quitar escribe un archivo vacío ({}), que la app ya no pinta. Si algo está mal escrito sale con
un error que dice qué, antes de tocar la release.
"""
import json
import os
import sys
import time
from urllib.parse import urlparse

MAX_TEXT_LENGTH = 400
MIN_HOURS = 1
MAX_HOURS = 720


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
    hours = hours_from(env.get('HORAS', '36'))
    notice = {
        'id': time.strftime('%Y%m%dT%H%M%SZ', time.gmtime(now)),
        'text': texts_from({'es': env.get('TEXTO_ES', ''), 'eu': env.get('TEXTO_EU', ''), 'en': env.get('TEXTO_EN', '')}),
        'expiresAt': int(now) + hours * 3600,
        'notify': env.get('NOTIFICAR', 'false').strip().lower() == 'true',
    }
    address = web_address(env.get('ENLACE', '').strip())
    if address is not None:
        notice['url'] = address
    return notice


def summary(notice):
    if notice == {}:
        return 'Aviso quitado: la app deja de enseñarlo en un par de minutos.'
    hours = round((notice['expiresAt'] - time.time()) / 3600)
    lines = [f"Aviso publicado (se muestra unas {hours} h): {notice['text']['es']}"]
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
