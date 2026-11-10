// La imagen para compartir (icons-bide-rojo/og-image.png, 1200 × 630) con todas las apps de api/Networks, la
// plantilla del cartel de parada en A5 (carteles/cartel-parada-a5.png y .pdf, sin el QR: va en el hueco del centro) y
// un cartel A5 por app con el QR que la abre (carteles/apps/cartel-<red>.png y .pdf).
// Uso: node scripts/render-images.mjs [og|cartel|apps]   (sin nada, todo). Usa el navegador de las pruebas (CHROME_PATH).
import { execFileSync, spawn } from 'node:child_process';
import { mkdirSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { qrSvg } from './qr.mjs';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const POSTERS = join(ROOT, '..', 'carteles');
const PORT = Number(process.env.CDP_PORT || 9398);
const CHROME = process.env.CHROME_PATH || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const asked = process.argv[2] || 'todo';

// Los ficheros de la app, incrustados: una página data: no puede cargar ficheros locales.
const embed = (file, type) => `data:${type};base64,${readFileSync(join(ROOT, file)).toString('base64')}`;
const fontFaces = [
    ['Bricolage Grotesque', 'bricolage-grotesque-latin.woff2', '500 800'],
    ['Bricolage Grotesque', 'bricolage-grotesque-latin-ext.woff2', '500 800'],
    ['Hanken Grotesk', 'hanken-grotesk-latin.woff2', '400 700'],
    ['Hanken Grotesk', 'hanken-grotesk-latin-ext.woff2', '400 700'],
].map(([family, file, weight]) => `@font-face{font-family:'${family}';font-weight:${weight};src:url(${embed('fonts/' + file, 'font/woff2')}) format('woff2')}`).join('');
const wordmark = embed('icons-bide-rojo/bide-wordmark-mayusculas.svg', 'image/svg+xml');
const base = `${fontFaces}*{box-sizing:border-box;margin:0}h1,p{text-wrap:balance}body{background:#fff;color:#16202a;font-family:'Hanken Grotesk',sans-serif}`;

// Las apps del registro, en el orden del menú: nombre e icono.
const php = 'require "api/Core/Networks.php"; $a = []; foreach (Core\\Networks::all() as $s => $n) $a[] = [$n["texts"]["es"]["name"], $n["brand"]["icons"], $s, $n["kind"], $n["brand"]["palette"]["primary"] ?? $n["brand"]["tile_color"], $n["texts"]]; echo json_encode($a);';
const apps = JSON.parse(execFileSync('php', ['-r', php], { cwd: ROOT, encoding: 'utf8' }));

// Hasta tres filas de apps, el tamaño de siempre; con más, todo se encoge lo justo para que quepan.
function ogPage() {
    const tiles = apps.map(([name, icons]) => `<li><img src="${embed(icons + '/icon-192.png', 'image/png')}" alt=""><span>${name}</span></li>`).join('');
    const rows = Math.ceil(apps.length / 4);
    let size = { mark: 108, title: 46, titleMargin: '26px 0 34px', icon: 64, gap: 22, text: 20 };
    if (rows > 3) size = { mark: 92, title: 42, titleMargin: '22px 0 28px', icon: Math.floor(312 / rows) - 18, gap: 18, text: 19 };
    return `<html><head><style>${base}
        main{width:1200px;height:630px;padding:50px 64px 0;overflow:hidden}
        .mark{height:${size.mark}px;display:block}
        h1{font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:${size.title}px;line-height:1.12;margin:${size.titleMargin};letter-spacing:-.01em}
        ul{list-style:none;padding:0;display:grid;grid-template-columns:repeat(4,1fr);gap:${size.gap}px 14px}
        li{display:flex;align-items:center;gap:14px;font-family:'Bricolage Grotesque',sans-serif;font-weight:700;font-size:${size.text}px;white-space:nowrap}
        li img{width:${size.icon}px;height:${size.icon}px;border-radius:15px;flex:none}
    </style></head><body><main><img class="mark" src="${wordmark}" alt="Bide+">
        <h1>Bus, metro, tren y tranvía de Euskadi<br>en un solo sitio</h1><ul>${tiles}</ul></main></body></html>`;
}

// A5 a 300 ppp: 1748 × 2480. El hueco blanco del centro es para el QR de la parada.
function posterPage() {
    return `<html><head><style>${base}
        @page{size:148mm 210mm;margin:0}
        main{width:1748px;height:2480px;padding:150px 150px 0;display:flex;flex-direction:column;align-items:center;text-align:center}
        .mark{height:190px}
        h1{font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:150px;line-height:1.02;letter-spacing:-.02em;color:#00573a;margin-top:120px}
        .es{font-size:76px;font-weight:600;line-height:1.2;margin-top:44px}
        .eu{font-size:58px;line-height:1.25;color:#5a6470;margin-top:36px}
        .qr{width:880px;height:880px;margin-top:90px;border:10px dashed #c8ced4;border-radius:48px;display:flex;align-items:center;justify-content:center;color:#b3bac1;font-size:54px;font-weight:600}
        .scan{font-size:46px;color:#5a6470;margin-top:44px;line-height:1.3}
        footer{margin-top:auto;width:100%;padding:56px 0 110px;border-top:6px solid #00573a;font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:74px;color:#00573a}
        footer small{display:block;font-family:'Hanken Grotesk',sans-serif;font-weight:500;font-size:42px;color:#5a6470;margin-top:14px}
    </style></head><body><main><img class="mark" src="${wordmark}" alt="Bide+">
        <h1>¿Cansado de perder el bus?</h1>
        <p class="es">Mira desde aquí el horario de esta parada</p>
        <p class="eu">Autobusa galtzeaz nekatuta? Begiratu hemendik geltoki honetako ordutegia</p>
        <div class="qr">QR</div>
        <p class="scan">Escanéalo con la cámara del móvil<br>Eskaneatu mugikorraren kamerarekin</p>
        <footer>www.bideplus.eus<small>Horarios y tiempo real · Ordutegiak eta denbora errealean</small></footer>
    </main></body></html>`;
}

// Las tres más usadas llevan su propio mensaje y lo que ofrecen; las demás, uno según el tipo de transporte y el
// subtítulo de su ficha, que solo dice «tiempo real» en las que lo tienen.
const FEATURED = {
    bus: {
        title: ['¿Cansado de perder el bus?', 'Autobusa galtzeaz nekatuta?'],
        lead: ['Mira dónde viene tu Bizkaibus, en directo', 'Ikusi non datorren zure Bizkaibusa, zuzenean'],
        points: [
            ['El autobús en el mapa, con su GPS', 'Autobusa mapan, bere GPSarekin'],
            ['Cuánto le falta para llegar a tu parada', 'Zenbat falta zaion zure geltokira iristeko'],
            ['Avisos de las líneas que usas', 'Erabiltzen dituzun lineen abisuak'],
        ],
    },
    metro: {
        title: ['¿Cuándo pasa el próximo metro?', 'Noiz dator hurrengo metroa?'],
        lead: ['Los próximos trenes de tu estación, en directo', 'Zure geltokiko hurrengo trenak, zuzenean'],
        points: [
            ['Minutos que faltan para cada tren', 'Tren bakoitzerako falta diren minutuak'],
            ['Horarios de todas las estaciones', 'Geltoki guztietako ordutegiak'],
            ['Incidencias de la red', 'Sareko gorabeherak'],
        ],
    },
    renfe: {
        title: ['¿Va con retraso tu tren?', 'Zure trena atzeratuta dator?'],
        lead: ['Retrasos y trenes cancelados de Cercanías, en directo', 'Aldirietako atzerapenak eta bertan behera utzitako trenak, zuzenean'],
        points: [
            ['Cuántos minutos de retraso lleva cada tren', 'Tren bakoitzak zenbat minutuko atzerapena daraman'],
            ['Cercanías de Bilbao y de Donostia', 'Bilboko eta Donostiako aldiriak'],
            ['Avisos de tu estación', 'Zure geltokiko abisuak'],
        ],
    },
};
const TITLE_BY_KIND = {
    bus: ['¿Cuándo pasa tu bus?', 'Noiz dator zure autobusa?'],
    tram: ['¿Cuándo pasa el próximo tranvía?', 'Noiz dator hurrengo tranbia?'],
    train: ['¿Cuándo sale tu tren?', 'Noiz irteten da zure trena?'],
    metro: ['¿Cuándo pasa el próximo metro?', 'Noiz dator hurrengo metroa?'],
};
const appUrl = (slug) => `https://www.bideplus.eus/?red=${slug}`;
// La primera frase del aviso de la ficha («Proyecto independiente y no oficial, sin relación con…»).
const firstSentence = (text) => text.split(/\.(?=\s+[A-ZÁÉÍÓÚ]|$)/)[0] + '.';

function appPosterPage([name, icons, slug, kind, color, texts]) {
    const featured = FEATURED[slug];
    const [title, titleEu] = featured?.title ?? TITLE_BY_KIND[kind] ?? TITLE_BY_KIND.bus;
    // El subtítulo de la ficha («Horarios y tiempo real de…» en las que tienen el directo del operador).
    let [lead, leadEu] = [texts.es.subtitle, texts.eu.subtitle];
    if (featured) {
        [lead, leadEu] = featured.lead;
    }
    let layout = '';
    let list = '';
    if (featured) {
        layout = 'featured';
        list = `<ul>${featured.points.map(([es, eu]) => `<li><b>${es}</b><span>${eu}</span></li>`).join('')}</ul>`;
    }
    const qr = `data:image/svg+xml;base64,${Buffer.from(qrSvg(appUrl(slug), '#111')).toString('base64')}`;
    return `<html><head><style>${base}
        html,body{overflow:hidden}body{background:${color}}
        main{width:1748px;height:2480px;padding:130px 140px 0;display:flex;flex-direction:column;color:#fff}
        .app{display:flex;align-items:center;gap:40px;font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:92px;letter-spacing:-.01em}
        .app img{width:190px;height:190px;border-radius:44px}
        h1{font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:150px;line-height:1.02;letter-spacing:-.02em;margin-top:130px}
        h2{font-family:'Bricolage Grotesque',sans-serif;font-weight:700;font-size:80px;line-height:1.08;opacity:.72;margin-top:18px}
        .lead{font-size:58px;font-weight:600;line-height:1.2;margin-top:70px}
        .lead-eu{font-size:46px;line-height:1.25;opacity:.75;margin-top:14px}
        ul{list-style:none;padding:0;margin-top:52px;display:grid;gap:24px}
        li{display:grid;grid-template-columns:62px 1fr;column-gap:30px;align-items:start}
        li::before{content:'';grid-row:1/3;width:62px;height:62px;border-radius:50%;margin-top:2px;background:#fff url("data:image/svg+xml;base64,${Buffer.from(`<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M6 12.5l4 4 8-9" fill="none" stroke="${color}" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>`).toString('base64')}") center/40px no-repeat}
        li b{font-size:50px;font-weight:700;line-height:1.15}
        li span{font-size:40px;opacity:.75;line-height:1.2;margin-top:4px}
        .card{margin:auto auto 0;background:#fff;color:#16202a;border-radius:56px;padding:60px 90px 54px;display:flex;flex-direction:column;align-items:center;text-align:center}
        .card img{width:760px;height:760px;display:block}
        .card p{font-size:42px;font-weight:600;line-height:1.3;margin-top:22px}
        .card p span{display:block;font-weight:400;color:#5a6470}
        footer{display:flex;align-items:center;justify-content:space-between;gap:40px;padding:50px 0 80px}
        footer .mark{height:96px;filter:brightness(0) invert(1)}
        footer .site{font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:64px}
        .note{font-size:27px;line-height:1.35;opacity:.7;text-align:center;margin-top:-50px;padding-bottom:70px}
        /* Con su lista de lo que ofrece, todo un poco más pequeño para que quepa en la hoja. */
        .featured h1{font-size:124px;margin-top:80px}
        .featured h2{font-size:70px}
        .featured .lead{margin-top:50px}
        .featured .card{padding:40px 60px 36px}
        .featured .card img{width:560px;height:560px}
    </style></head><body><main class="${layout}">
        <div class="app"><img src="${embed(icons + '/icon-192.png', 'image/png')}" alt="">${name}</div>
        <h1>${title}</h1><h2>${titleEu}</h2>
        <p class="lead">${lead}</p><p class="lead-eu">${leadEu}</p>
        ${list}
        <div class="card"><img src="${qr}" alt=""><p>Escanéalo con la cámara del móvil<span>Eskaneatu mugikorraren kamerarekin</span></p></div>
        <footer><img class="mark" src="${wordmark}" alt="Bide+"><span class="site">www.bideplus.eus</span></footer>
        <p class="note">${firstSentence(texts.es.disclaimer)}<br>${firstSentence(texts.eu.disclaimer)}</p>
    </main></body></html>`;
}

const flags = ['--headless=new', '--disable-gpu', `--remote-debugging-port=${PORT}`, `--user-data-dir=${mkdtempSync(join(tmpdir(), 'bide-images-'))}`, 'about:blank'];
if (process.env.CI) flags.unshift('--no-sandbox');
const browser = spawn(CHROME, flags, { stdio: 'ignore' });
let targets;
for (let i = 0; i < 40 && !targets; i++) {
    await wait(500);
    try {
        targets = await (await fetch(`http://127.0.0.1:${PORT}/json`)).json();
    } catch (e) {
        // Todavía arrancando.
    }
}
if (!targets) {
    console.error(`No se ha podido abrir el navegador (${CHROME}); pon su ruta en CHROME_PATH.`);
    browser.kill();
    process.exit(1);
}
const socket = new WebSocket(targets.find((target) => target.type === 'page').webSocketDebuggerUrl);
await new Promise((resolve) => (socket.onopen = resolve));
let lastId = 0;
const waiting = new Map();
socket.onmessage = (event) => {
    const message = JSON.parse(event.data);
    if (message.id && waiting.has(message.id)) {
        waiting.get(message.id)(message.result);
        waiting.delete(message.id);
    }
};
const call = (method, params = {}) => new Promise((resolve) => {
    lastId += 1;
    waiting.set(lastId, resolve);
    socket.send(JSON.stringify({ id: lastId, method, params }));
});

async function render(html, width, height) {
    await call('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: false });
    await call('Page.navigate', { url: `data:text/html;charset=utf-8;base64,${Buffer.from(html).toString('base64')}` });
    await wait(1200);
    const shot = await call('Page.captureScreenshot', { format: 'png', clip: { x: 0, y: 0, width, height, scale: 1 } });
    return Buffer.from(shot.data, 'base64');
}

// La página que hay abierta (1748 px de ancho), en una hoja A5 sin márgenes.
async function printA5() {
    const pdf = await call('Page.printToPDF', { paperWidth: 148 / 25.4, paperHeight: 210 / 25.4, marginTop: 0, marginBottom: 0, marginLeft: 0, marginRight: 0, printBackground: true, scale: 148 / 25.4 * 96 / 1748 });
    return Buffer.from(pdf.data, 'base64');
}

if (asked === 'todo' || asked === 'og') {
    writeFileSync(join(ROOT, 'icons-bide-rojo/og-image.png'), await render(ogPage(), 1200, 630));
    process.stdout.write(`icons-bide-rojo/og-image.png (1200 × 630, ${apps.length} apps)\n`);
}
if (asked === 'todo' || asked === 'cartel') {
    mkdirSync(POSTERS, { recursive: true });
    writeFileSync(join(POSTERS, 'cartel-parada-a5.png'), await render(posterPage(), 1748, 2480));
    writeFileSync(join(POSTERS, 'cartel-parada-a5.pdf'), await printA5());
    process.stdout.write('carteles/cartel-parada-a5.png (A5 a 300 ppp) y cartel-parada-a5.pdf\n');
}
if (asked === 'todo' || asked === 'apps') {
    mkdirSync(join(POSTERS, 'apps'), { recursive: true });
    for (const app of apps) {
        const file = join(POSTERS, 'apps', `cartel-${app[2]}`);
        writeFileSync(`${file}.png`, await render(appPosterPage(app), 1748, 2480));
        writeFileSync(`${file}.pdf`, await printA5());
        process.stdout.write(`carteles/apps/cartel-${app[2]}.png y .pdf → ${appUrl(app[2])}\n`);
    }
}
socket.close();
browser.kill();
