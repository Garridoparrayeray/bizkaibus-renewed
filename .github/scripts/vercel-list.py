"""Lista los despliegues del proyecto de Vercel página a página (hasta 1000) y los imprime como un solo JSON.

Variables: VERCEL_TOKEN, VERCEL_PROJECT_ID y, si es un equipo, VERCEL_ORG_ID (team_...). Si falla algo sale con error.
"""
import json
import os
import urllib.request

project = os.environ['VERCEL_PROJECT_ID']
org = os.environ.get('VERCEL_ORG_ID', '')
headers = {'Authorization': 'Bearer ' + os.environ['VERCEL_TOKEN']}
deployments = []
until = ''
for _ in range(10):
    url = 'https://api.vercel.com/v6/deployments?projectId=' + project + '&limit=100' + until
    if org.startswith('team_'):
        url += '&teamId=' + org
    with urllib.request.urlopen(urllib.request.Request(url, headers=headers), timeout=30) as response:
        page = json.load(response)
    deployments.extend(page['deployments'])
    next_page = page.get('pagination', {}).get('next')
    if not next_page:
        break
    until = '&until=' + str(next_page)
print(json.dumps({'deployments': deployments}))
