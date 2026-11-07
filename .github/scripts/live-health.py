"""Vigila el directo de cada red en producción con /api/status y falla si algo no cuadra.

Uso: python3 live-health.py <url base> <red> [<red>...]
Errores (el flujo sale en rojo y GitHub avisa por correo):
- el feed de una red con directo no responde o viene vacío (salvo Bilbobus y Lurraldebus, que lo publican vacío);
- una red que se empareja por viaje no encuentra ningún viaje del feed en su horario;
- los retrasos que se enseñan no son creíbles: mediana de más de 30 min o percentil 95 de más de 60.
Los dos primeros, solo si según el horario van en marcha al menos MIN_SCHEDULED viajes de la red («scheduled» de
/api/status; sin él, de 00:30 a 06:00 no cuentan): de noche o con un feed compartido (Euskotren publica uno para sus
trenes y los dos tranvías), que no haya nada de la red es lo normal.
Avisos (en el resumen, sin fallar): el feed copia el horario o sus horas contradicen al GPS (ya se tratan solos).
Una red con error se vuelve a mirar al minuto: un feed que viene vacío en una sola lectura no es un fallo.
"""
import datetime
import json
import os
import sys
import time
import urllib.error
import urllib.request
from zoneinfo import ZoneInfo

EMPTY_FEEDS_KNOWN = {'bilbobus', 'lurraldebus'}
MIN_LIVE = 5
MIN_SCHEDULED = 3
MAX_MEDIAN_MINUTES = 30
MAX_P95_MINUTES = 60
RETRY_SECONDS = 60


def night():
    now = datetime.datetime.now(datetime.timezone.utc).astimezone(ZoneInfo('Europe/Madrid'))
    minutes = now.hour * 60 + now.minute
    return 30 <= minutes < 6 * 60


def status(base, network, attempt=1):
    try:
        with urllib.request.urlopen(f'{base}/api/status?red={network}&intento={attempt}', timeout=60) as response:
            return json.load(response)
    except urllib.error.HTTPError as error:
        if error.code == 404:
            return None
        return {'error': f'HTTP {error.code}'}
    except Exception as error:
        return {'error': str(error)}


def little_scheduled(report):
    scheduled = report.get('scheduled')
    if scheduled is None:
        return night()
    return scheduled < MIN_SCHEDULED


def check(network, report):
    errors = []
    notices = []
    if report is None:
        return errors, notices, 'sin directo'
    if report.get('error') or not report.get('ok'):
        message = f"el feed no responde o viene vacío ({report.get('error') or 'sin datos'})"
        if network in EMPTY_FEEDS_KNOWN or little_scheduled(report):
            notices.append(message)
        else:
            errors.append(message)
    if report.get('matching') == 'stops' and report.get('feedTrips'):
        message = 'ningún viaje del feed está en el horario: se empareja por paradas y hora'
        if little_scheduled(report):
            notices.append(message)
        else:
            errors.append(message)
    if report.get('copiesSchedule'):
        notices.append('el feed copia el horario: sale con el horario')
    if report.get('contradictsVehicles'):
        notices.append('las horas del feed contradicen al GPS: manda el GPS')
    delays = report.get('delayMinutes')
    if delays and report.get('live', 0) >= MIN_LIVE:
        if delays['median'] > MAX_MEDIAN_MINUTES or delays['p95'] > MAX_P95_MINUTES:
            errors.append(f"retrasos no creíbles: mediana {delays['median']} min, p95 {delays['p95']} min")
    summary = []
    for key in ('scheduled', 'vehicles', 'feedTrips', 'matched', 'live'):
        if key in report:
            summary.append(f'{key} {report[key]}')
    if delays:
        summary.append(f"retraso mediana {delays['median']} · p95 {delays['p95']} · máx {delays['max']} min")
    return errors, notices, ', '.join(summary)


def main():
    base = sys.argv[1].rstrip('/')
    lines = ['### Directo de cada red', '', '| Red | Estado | Datos |', '| --- | --- | --- |']
    failed = False
    for network in sys.argv[2:]:
        errors, notices, summary = check(network, status(base, network))
        if errors:
            print(f'{network}: ' + '; '.join(errors) + f'; se vuelve a mirar en {RETRY_SECONDS} s')
            time.sleep(RETRY_SECONDS)
            errors, notices, summary = check(network, status(base, network, 2))
        state = 'bien'
        if notices:
            state = 'aviso: ' + '; '.join(notices)
        if errors:
            state = 'ERROR: ' + '; '.join(errors)
            failed = True
            print(f'::error::{network}: ' + '; '.join(errors))
        for notice in notices:
            print(f'::notice::{network}: {notice}')
        lines.append(f'| {network} | {state} | {summary} |')
        print(f'{network}: {state} | {summary}')
    summary_file = os.environ.get('GITHUB_STEP_SUMMARY')
    if summary_file:
        with open(summary_file, 'a', encoding='utf-8') as out:
            out.write('\n'.join(lines) + '\n')
    if failed:
        sys.exit(1)


main()
