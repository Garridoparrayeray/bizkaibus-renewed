"""Poda un acumulado JSON Lines a los últimos días: deja las líneas cuyo día («date» o «fecha», AAAA-MM-DD) no es
anterior a hoy menos <días>, y las que no dicen su día. Lo usa captura-continua.sh con coverage-samples.jsonl y
eta-eval.jsonl, que el motor no poda.

Uso: python3 podar-acumulado.py <fichero> <días> [<hoy AAAA-MM-DD>]
"""
import datetime
import json
import os
import sys


def keep(line, limit):
    try:
        row = json.loads(line)
    except ValueError:
        return False
    day = row.get('date') or row.get('fecha')
    return not day or str(day) >= limit


def main():
    path, days = sys.argv[1], int(sys.argv[2])
    today = datetime.date.today()
    if len(sys.argv) > 3:
        today = datetime.date.fromisoformat(sys.argv[3])
    limit = (today - datetime.timedelta(days=days)).isoformat()
    with open(path, encoding='utf-8') as source:
        kept = [line for line in source if keep(line, limit)]
    with open(path + '.poda', 'w', encoding='utf-8') as out:
        out.writelines(kept)
    os.replace(path + '.poda', path)


if __name__ == '__main__':
    main()
