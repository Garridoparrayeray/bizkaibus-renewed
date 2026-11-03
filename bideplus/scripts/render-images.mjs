// La imagen para compartir (icons-bide-rojo/og-image.png, 1200 × 630) con todas las apps de api/Networks, y la
// plantilla del cartel de parada en A5 (carteles/cartel-parada-a5.png y .pdf, sin el QR: va en el hueco del centro).
// Uso: node scripts/render-images.mjs [og|cartel]   (sin nada, las dos). Usa el navegador de las pruebas (CHROME_PATH).
import { execFileSync, spawn } from 'node:child_process';
import { mkdirSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

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
const php = 'require "api/Core/Networks.php"; $a = []; foreach (Core\\Networks::all() as $n) $a[] = [$n["texts"]["es"]["name"], $n["brand"]["icons"]]; echo json_encode($a);';
const apps = JSON.parse(execFileSync('php', ['-r', php], { cwd: ROOT, encoding: 'utf8' }));

function ogPage() {
    const tiles = apps.map(([name, icons]) => `<li><img src="${embed(icons + '/icon-192.png', 'image/png')}" alt=""><span>${name}</span></li>`).join('');
    return `<html><head><style>${base}
        main{width:1200px;height:630px;padding:50px 64px 0}
        .mark{height:108px;display:block}
        h1{font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:46px;line-height:1.12;margin:26px 0 34px;letter-spacing:-.01em}
        ul{list-style:none;padding:0;display:grid;grid-template-columns:repeat(4,1fr);gap:22px 14px}
        li{display:flex;align-items:center;gap:14px;font-family:'Bricolage Grotesque',sans-serif;font-weight:700;font-size:20px;white-space:nowrap}
        li img{width:64px;height:64px;border-radius:15px;flex:none}
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
        <footer>www.bideplus.eus<small>Horarios y tiempo real · Ordutegiak eta denbora erreala</small></footer>
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

if (asked === 'todo' || asked === 'og') {
    writeFileSync(join(ROOT, 'icons-bide-rojo/og-image.png'), await render(ogPage(), 1200, 630));
    process.stdout.write(`icons-bide-rojo/og-image.png (1200 × 630, ${apps.length} apps)\n`);
}
if (asked === 'todo' || asked === 'cartel') {
    mkdirSync(POSTERS, { recursive: true });
    writeFileSync(join(POSTERS, 'cartel-parada-a5.png'), await render(posterPage(), 1748, 2480));
    const pdf = await call('Page.printToPDF', { paperWidth: 148 / 25.4, paperHeight: 210 / 25.4, marginTop: 0, marginBottom: 0, marginLeft: 0, marginRight: 0, printBackground: true, scale: 148 / 25.4 * 96 / 1748 });
    writeFileSync(join(POSTERS, 'cartel-parada-a5.pdf'), Buffer.from(pdf.data, 'base64'));
    process.stdout.write('carteles/cartel-parada-a5.png (A5 a 300 ppp) y cartel-parada-a5.pdf\n');
}
socket.close();
browser.kill();
