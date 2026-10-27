#!/usr/bin/env python3
"""
Datos de la página interactiva docs/aprendizaje.html: agrega las muestras de tramos acumuladas por la captura horaria
(release "pace-data" de GitHub, fichero segment-samples.jsonl) en un JSON pequeño (unos 50 KB).

Uso (desde la carpeta bideplus):
  curl -L -o /tmp/segment-samples.jsonl https://github.com/Garridoparrayeray/bizkaibus-renewed/releases/download/pace-data/segment-samples.jsonl
  python3 scripts/learning-viz-data.py /tmp/segment-samples.jsonl --page ../docs/aprendizaje.html   # reescribe la línea "const D = ...;"
  python3 scripts/learning-viz-data.py /tmp/segment-samples.jsonl                                   # o imprime el JSON

Qué calcula: la curva de aprendizaje por días (entrena con los días hasta D y prueba con los dos últimos días, que no entran
en el entrenamiento), unos tramos con todas sus medidas, la proporción tiempo real / horario según el largo del tramo (con el
error por tramo con k único y por largo, por mitades de buses) y la mediana por franja. El largo de cada tramo sale del GTFS
(passing_times.dist_m de data/bizkaibus.sqlite).
"""
import sys, re, pathlib
ROOT = pathlib.Path(__file__).resolve().parents[1]
args = [a for a in sys.argv[1:] if not a.startswith('--')]
if not args:
    sys.exit(__doc__)
SAMPLES = args[0]
PAGE = None
if '--page' in sys.argv:
    PAGE = pathlib.Path(sys.argv[sys.argv.index('--page') + 1]).resolve()
import sqlite3, json, statistics as st, collections, zlib, random, datetime
random.seed(7)
c=sqlite3.connect(str(ROOT/'data'/'bizkaibus.sqlite'))
# nombres de parada y distancia por par
names={str(a):b for a,b in c.execute("select id,name from stops")}
d=collections.defaultdict(list); prev=None
for sj,seq,stop,dist in c.execute("select service_journey_id,seq_order,stop_id,dist_m from passing_times order by service_journey_id,seq_order"):
    if prev and prev[0]==sj and dist is not None and prev[3] is not None: d[(prev[2],str(stop))].append(dist-prev[3])
    prev=(sj,seq,str(stop),dist)
dist={k:st.median(v) for k,v in d.items()}
S=[json.loads(l) for l in open(SAMPLES)]
for s in S: s['key']=(str(s['from']),str(s['to'])); s['d']=dist.get(s['key'])
days=sorted({s['date'] for s in S})
cuts=[300,600,1000,1500,3000]
def bk(m):
    for i,x in enumerate(cuts):
        if m<x: return i
    return 5
pf=json.load(open(ROOT/'engine'/'data'/'pace-factors.json'))
def kprod(line,band):
    v=pf['lines'].get(line,{}).get(band)
    if v is None: v=pf['global'].get(band,1.0)
    return max(0.6,min(1.2,v))
def fit(tr):
    g=collections.defaultdict(lambda: collections.defaultdict(dict))
    for s in tr: g[s['key']][s['band']][s['track']]=s['actual']
    rat=collections.defaultdict(list)
    for s in tr:
        if s['d'] and s['scheduled']>0: rat[bk(s['d'])].append(s['actual']/s['scheduled'])
    return g,{k:st.median(v) for k,v in rat.items() if len(v)>=20}
# --- 1) curva de aprendizaje por días: entrenar con los días <= D, probar en 14 y 15 oct
test=[s for s in S if s['date'] in ('2026-10-14','2026-10-15')]
train_days=[x for x in days if x<'2026-10-14']
curve=[]
for i,D in enumerate(train_days):
    tr=[s for s in S if s['date']<=D]
    g,r=fit(tr)
    cov=0;eb=[];ea=[];ek=[]
    for s in test:
        v=g.get(s['key'],{}).get(s['band'],{})
        if len(v)<2:
            allb=g.get(s['key'],{})
            pooled={}
            for bb in allb.values(): pooled.update(bb)
            if len(allb)>=2 and len(pooled)>=2: v=pooled   # como en producción: tiempo general si hay datos de varias franjas
        learned=len(v)>=2
        kb=abs(kprod(s['line'],s['band'])*s['scheduled']-s['actual'])
        ls=r.get(bk(s['d']),None) if s['d'] else None
        fb=abs(ls*s['scheduled']-s['actual']) if ls else kb
        app=abs(st.median(v.values())-s['actual']) if learned else fb
        cov+=learned; eb.append(kb); ea.append(app); ek.append(fb)
    n=len(test)
    curve.append({'dia':D,'buses':len({s['track'] for s in tr}),'muestras':len(tr),'tramos':len({s['key'] for s in tr}),'cobertura':round(100*cov/n,1),'err_k':round(st.mean(eb),1),'err_app':round(st.mean(ea),1),'err_largo':round(st.mean(ek),1)})
# --- 2) tramos para ver cómo converge la mediana
bykey=collections.defaultdict(list)
for s in S: bykey[s['key']].append(s)
cands=[(k,v) for k,v in bykey.items() if len(v)>=35 and v[0]['d']]
def pick(lo,hi,n):
    c_=[(k,v) for k,v in cands if lo<=v[0]['d']<hi]; c_.sort(key=lambda kv:-len(kv[1])); return c_[:n]
chosen=pick(0,500,2)+pick(500,1000,2)+pick(1000,1500,2)+pick(1500,3000,2)+pick(3000,100000,3)
segs=[]
for k,v in chosen:
    v=sorted(v,key=lambda s:(s['date'],s['at']))
    v=v[:160]
    sched=st.median([s['scheduled'] for s in v])
    segs.append({'id':f"{k[0]}>{k[1]}",'de':names.get(k[0],k[0]),'a':names.get(k[1],k[1]),'metros':round(v[0]['d']),'horario':round(sched),'k':round(kprod(v[0]['line'],v[0]['band'])*sched),
      'pts':[[s['date'][5:],s['band'].split('.')[1][:3],round(s['actual'])] for s in v]})
# --- 3) proporción real/horario según el largo (puntos y medianas)
pts=[s for s in S if s['d'] and s['scheduled']>=20 and 0.05<=s['actual']/s['scheduled']<=3]
smp=random.sample(pts,1800)
scatter=[[round(s['d']),round(s['actual']/s['scheduled'],3)] for s in smp]
buck=collections.defaultdict(list)
for s in pts: buck[bk(s['d'])].append(s['actual']/s['scheduled'])
bmed=[{'cubo':i,'n':len(v),'med':round(st.median(v),3),'p25':round(sorted(v)[len(v)//4],3),'p75':round(sorted(v)[3*len(v)//4],3)} for i,v in sorted(buck.items())]
# error por tramo: k de ritmo vs proporción por largo (validación por mitades de buses)
def half(fold):
    tr=[s for s in pts if zlib.crc32(s['track'].encode())%2!=fold]; te=[s for s in pts if zlib.crc32(s['track'].encode())%2==fold]
    r=collections.defaultdict(list)
    for s in tr: r[bk(s['d'])].append(s['actual']/s['scheduled'])
    r={k:st.median(v) for k,v in r.items()}
    return [(abs(kprod(s['line'],s['band'])*s['scheduled']-s['actual']),abs(r[bk(s['d'])]*s['scheduled']-s['actual']),bk(s['d'])) for s in te if bk(s['d']) in r]
e=half(0)+half(1)
err={'k':round(st.mean(a for a,b,_ in e),1),'largo':round(st.mean(b for a,b,_ in e),1),'n':len(e),
 'por_cubo':[{'cubo':i,'k':round(st.mean(a for a,b,c_ in e if c_==i),1),'largo':round(st.mean(b for a,b,c_ in e if c_==i),1),'n':sum(1 for x in e if x[2]==i)} for i in range(6)]}
# --- 4) por franja: mediana de proporción real/horario
band=collections.defaultdict(list)
for s in pts: band[s['band']].append(s['actual']/s['scheduled'])
order=['laborable.manana','laborable.mediodia','laborable.punta_tarde','laborable.noche','fin_semana.punta_tarde','fin_semana.noche']
bands=[{'franja':b,'n':len(band[b]),'med':round(st.median(band[b]),3),'k':pf['global'].get(b)} for b in order if band.get(b)]
# --- 5) muestras por día
perday=collections.Counter(s['date'] for s in S)
out={'generado':datetime.date.today().isoformat(),'muestras':len(S),'buses':len({s['track'] for s in S}),'tramos':len({s['key'] for s in S}),
 'dias':[{'dia':k,'n':perday[k]} for k in days],'curva':curve,'segmentos':segs,'dispersion':scatter,'cubos':bmed,'error_largo':err,'franjas':bands,'cortes':cuts}
js=json.dumps(out,ensure_ascii=False,separators=(',',':'))
if PAGE:
    page=PAGE.read_text()
    new,n=re.subn(r'^const D = .*;$',lambda m:'const D = '+js+';',page,count=1,flags=re.M)
    if n!=1: sys.exit('No se encontró la línea "const D = ...;" en '+str(PAGE))
    PAGE.write_text(new); print('Página actualizada:',PAGE,'(',len(js)//1024,'KB de datos )')
else:
    print(js)
