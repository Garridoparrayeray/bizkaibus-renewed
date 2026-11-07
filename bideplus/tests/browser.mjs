import { spawn } from 'node:child_process';
import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

// El navegador de las pruebas de la interfaz (Chrome o Edge sin ventana, en castellano: la app sigue el idioma del
// navegador y las pruebas comprueban los textos en castellano). En GitHub a veces no llega a responder: se le dan 20 s
// y, si no, se cierra y se lanza otra vez, hasta tres. Devuelve el proceso y sus pestañas, o null si no hubo manera.
const sleep = ms => new Promise(r => setTimeout(r, ms));

export async function launchBrowser(port, profilePrefix) {
    const chrome = process.env.CHROME_PATH || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
    for (let attempt = 1; attempt <= 3; attempt++) {
        const profileDir = mkdtempSync(join(tmpdir(), profilePrefix));
        const flags = ['--headless=new', '--disable-gpu', '--lang=es-ES', '--accept-lang=es-ES,es', `--remote-debugging-port=${port}`, `--user-data-dir=${profileDir}`, 'about:blank'];
        if (process.env.CI) flags.unshift('--no-sandbox');
        const browser = spawn(chrome, flags, { stdio: 'ignore' });
        browser.on('error', () => { });
        for (let i = 0; i < 40; i++) {
            await sleep(500);
            try {
                const targets = await (await fetch(`http://127.0.0.1:${port}/json`)).json();
                if (targets.some(x => x.type === 'page')) return { browser, targets };
            } catch (e) { }
        }
        try { browser.kill(); } catch (e) { }
        console.log(`(el navegador no respondió en el intento ${attempt} de 3)`);
        await sleep(1000);
    }
    return { browser: null, targets: null };
}
