import { execFileSync, spawnSync } from 'node:child_process';
import { copyFileSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import http from 'node:http';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

// El lanzador de las funciones de Vercel (scripts/vercel-launcher.py) con el vercel-php de vercel.json y un PHP de
// verdad: con muchas peticiones a la vez en el arranque en frío, ninguna falla (antes, ECONNREFUSED en casi todas), y
// si PHP se cae, la siguiente lo vuelve a arrancar. Sin npm o sin PHP lo dice y no prueba nada.
// Uso: node tests/vercel-launcher.mjs

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const AT_ONCE = 20;
const AFTER_CRASH = 5;
const PORT = 4567;
const SETTLE_MS = 300;
const PHP_PATTERN = '[p]hp -c php.ini -S 0.0.0.0:3000';
const results = [];
const check = (sName, bOk, detail) => {
    results.push(bOk);
    if (bOk) {
        console.log('OK  ' + sName);
        return;
    }
    console.log('MAL ' + sName + ' — ' + JSON.stringify(detail));
};

const runtime = JSON.parse(readFileSync(join(ROOT, 'vercel.json'), 'utf8')).functions['api/index.php'].runtime;
const folder = mkdtempSync(join(tmpdir(), 'bide-launcher-'));
const packed = spawnSync('npm', ['pack', runtime, '--silent'], { cwd: folder, encoding: 'utf8' });
if (packed.status !== 0 || spawnSync('php', ['-v']).status !== 0) {
    console.log('SALTA lanzador de Vercel: sin npm (' + runtime + ') o sin PHP');
    rmSync(folder, { recursive: true, force: true });
    process.exit(0);
}
execFileSync('tar', ['xzf', packed.stdout.trim()], { cwd: folder });
const task = join(folder, 'task');
mkdirSync(join(task, 'user'), { recursive: true });
copyFileSync(join(folder, 'package/dist/launchers/helpers.js'), join(task, 'helpers.js'));
const script = readFileSync(join(ROOT, 'scripts/vercel-launcher.py'), 'utf8');
const adapter = script.match(/ADAPTER = r'''([\s\S]*?)'''/)[1];
writeFileSync(join(task, 'launcher.js'), readFileSync(join(folder, 'package/dist/launchers/builtin.js'), 'utf8') + '\n' + adapter);
writeFileSync(join(task, 'user/index.php'), '<?php echo "ok";');

process.env.LAMBDA_TASK_ROOT = task;
process.env.NOW_PHP_DEV = '1';
const handler = createRequire(import.meta.url)(join(task, 'launcher.js'));
const server = http.createServer((req, res) => handler(req, res));
await new Promise((resolve) => server.listen(PORT, resolve));
const ask = () => new Promise((resolve) => {
    http.get(`http://127.0.0.1:${PORT}/index.php`, (res) => {
        let sBody = '';
        res.on('data', (chunk) => sBody += chunk);
        res.on('end', () => resolve([res.statusCode, sBody.slice(0, 80)]));
    }).on('error', (error) => resolve([0, String(error)]));
});
const failed = (aAnswers) => aAnswers.filter(([iStatus, sBody]) => iStatus !== 200 || sBody !== 'ok');
const killPhp = () => spawnSync('sh', ['-c', `pkill -f '${PHP_PATTERN}' || true`]);

try {
    const cold = failed(await Promise.all(Array.from({ length: AT_ONCE }, ask)));
    check(`arranque en frío con ${AT_ONCE} peticiones a la vez: todas esperan a que PHP escuche`, cold.length === 0, cold[0]);
    killPhp();
    await new Promise((resolve) => setTimeout(resolve, SETTLE_MS));
    const crashed = failed(await Promise.all(Array.from({ length: AFTER_CRASH }, ask)));
    check('con PHP caído, la siguiente lo vuelve a arrancar', crashed.length === 0, crashed[0]);
} finally {
    server.close();
    killPhp();
    rmSync(folder, { recursive: true, force: true });
}
if (!results.every(Boolean)) {
    console.log('RESULTADO lanzador de Vercel: FALLA');
    process.exit(1);
}
console.log('RESULTADO lanzador de Vercel: OK');
