// Los PNG de una red a partir de su dibujo: node scripts/render-icons.mjs <red>
//
// Lee icons-<red>/icon.svg (512 × 512: el fondo con esquinas redondeadas y la marca encima) y, si está,
// icons-<red>/glyph.svg (la marca en negro, para la cabecera), y escribe icon-512.png, icon-192.png,
// apple-touch-icon.png (180, sin esquinas: las pone el iPhone), icon-maskable-512.png (sin esquinas y con la marca en
// la zona segura del 80 %) y glyph.png (192). Usa el mismo navegador que las pruebas (CHROME_PATH).
import { spawn } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const PORT = Number(process.env.CDP_PORT || 9399);
const CHROME = process.env.CHROME_PATH || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const red = process.argv[2];
const folder = join(ROOT, `icons-${red}`);
if (!red || !existsSync(join(folder, 'icon.svg'))) {
    console.error('Uso: node scripts/render-icons.mjs <red>   (hace falta icons-<red>/icon.svg)');
    process.exit(1);
}

// Las versiones del dibujo: el icono tal cual, sin esquinas y con la marca reducida a la zona segura.
const icon = readFileSync(join(folder, 'icon.svg'), 'utf8');
const square = icon.replace(/(<rect width="512" height="512") rx="[\d.]+"/, '$1');
const maskable = square.replace(/(<rect width="512" height="512"[^>]*\/>)([\s\S]*)<\/svg>/, '$1<g transform="translate(51.2 51.2) scale(.8)">$2</g></svg>');
const jobs = [
    [icon, 512, 'icon-512.png'],
    [icon, 192, 'icon-192.png'],
    [square, 180, 'apple-touch-icon.png'],
    [maskable, 512, 'icon-maskable-512.png'],
];
if (existsSync(join(folder, 'glyph.svg'))) jobs.push([readFileSync(join(folder, 'glyph.svg'), 'utf8'), 192, 'glyph.png']);

const flags = ['--headless=new', '--disable-gpu', `--remote-debugging-port=${PORT}`, `--user-data-dir=${mkdtempSync(join(tmpdir(), 'bide-icons-'))}`, 'about:blank'];
if (process.env.CI) flags.unshift('--no-sandbox');
const browser = spawn(CHROME, flags, { stdio: 'ignore' });

let targets;
for (let i = 0; i < 40 && !targets; i++) {
    await sleep(500);
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
const ws = new WebSocket(targets.find((target) => target.type === 'page').webSocketDebuggerUrl);
await new Promise((resolve) => (ws.onopen = resolve));
let id = 0;
const pending = new Map();
ws.onmessage = (event) => {
    const message = JSON.parse(event.data);
    if (message.id && pending.has(message.id)) {
        pending.get(message.id)(message.result);
        pending.delete(message.id);
    }
};
const send = (method, params = {}) => new Promise((resolve) => {
    id += 1;
    pending.set(id, resolve);
    ws.send(JSON.stringify({ id, method, params }));
});

// Una ventana donde quepa el icono más grande, a escala 1, y con el fondo transparente: fuera de las esquinas
// redondeadas no queda nada pintado.
await send('Emulation.setDeviceMetricsOverride', { width: 600, height: 600, deviceScaleFactor: 1, mobile: false });
await send('Emulation.setDefaultBackgroundColorOverride', { color: { r: 0, g: 0, b: 0, a: 0 } });
for (const [svg, size, file] of jobs) {
    const page = `<html><body style="margin:0">${svg.replace('<svg ', `<svg width="${size}" height="${size}" `)}</body></html>`;
    await send('Page.navigate', { url: `data:text/html;base64,${Buffer.from(page).toString('base64')}` });
    await sleep(300);
    const shot = await send('Page.captureScreenshot', { format: 'png', clip: { x: 0, y: 0, width: size, height: size, scale: 1 } });
    writeFileSync(join(folder, file), Buffer.from(shot.data, 'base64'));
    process.stdout.write(`icons-${red}/${file} (${size} × ${size})\n`);
}
ws.close();
browser.kill();
