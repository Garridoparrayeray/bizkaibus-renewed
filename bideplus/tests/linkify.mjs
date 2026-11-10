// Direcciones web en los avisos (js/linkify.js). Uso: node tests/linkify.mjs
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../js/linkify.js', import.meta.url), 'utf8');
const BideLinks = vm.runInNewContext(source + '\nBideLinks', { URL });

const results = [];
function check(name, ok, detail = '') {
    results.push(ok);
    if (ok) {
        console.log('OK  ' + name);
        return;
    }
    console.log('MAL ' + name + ' — ' + detail);
}
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);

const strike = 'Consulta la afección en el siguiente enlace: https://www.bizkaia.eus/es/web/bizkaibus/-/general-11-de-noviembre-de-2026';
const parts = BideLinks.split(strike);
check('la dirección del consorcio sale como enlace, entera', parts.length === 2 && parts[1].url === 'https://www.bizkaia.eus/es/web/bizkaibus/-/general-11-de-noviembre-de-2026', JSON.stringify(parts));
check('el texto de antes se conserva', parts[0].text === 'Consulta la afección en el siguiente enlace: ');

const dotted = BideLinks.split('Más en https://www.bizkaia.eus/avisos. Gracias');
check('el punto final de la frase no entra en el enlace', dotted[1].url === 'https://www.bizkaia.eus/avisos' && dotted[2].text === '. Gracias', JSON.stringify(dotted));

const parenthesis = BideLinks.split('(ver https://metrobilbao.eus/es/avisos)');
check('el paréntesis que cierra no entra en el enlace', parenthesis[1].url === 'https://metrobilbao.eus/es/avisos' && parenthesis[2].text === ')', JSON.stringify(parenthesis));

const two = BideLinks.split('Uno http://a.eus/x y otro https://b.eus/y?z=1&w=2');
check('dos direcciones, cada una su enlace', two.filter((part) => part.url).map((part) => part.url).join(' ') === 'http://a.eus/x https://b.eus/y?z=1&w=2', JSON.stringify(two));

check('sin direcciones, un solo trozo de texto', same(BideLinks.split('Sin enlaces'), [{ text: 'Sin enlaces' }]));
check('texto vacío, sin trozos', same(BideLinks.split(''), []));

const script = BideLinks.split('javascript:alert(1) y https://ok.eus/a');
check('solo http y https se vuelven enlaces', script.filter((part) => part.url).length === 1 && script[0].text.startsWith('javascript:alert(1)'), JSON.stringify(script));

const markup = BideLinks.split('<a href="https://x.eus">hola</a> https://y.eus/z');
check('el HTML del aviso se queda como texto', markup.filter((part) => part.url).map((part) => part.url).join(' ') === 'https://x.eus https://y.eus/z' && markup[0].text.includes('<a href='), JSON.stringify(markup));

const failed = results.filter((ok) => !ok).length;
console.log(`\nRESULTADO enlaces de los avisos: ${results.length - failed} OK, ${failed} MAL`);
if (failed > 0) process.exit(1);
