// Periodos del horario oficial (js/app/schedule-periods.js). Uso: node tests/schedule-periods.mjs
import { schedulePeriods } from '../js/app/schedule-periods.js';

const block = (season, from, to, outbound) => ({
    'DENBORALDIA-TEMPORADA': season,
    'NOIZTIK-DESDE': from,
    'NOIZ_ARTE-HASTA': to,
    'JOAN_EUS-IDA_EUS': 'Bilbotik irteerak',
    'JOAN_CAS-IDA_CAS': outbound,
    'ETORRI_CAS-VUELTA_CAS': 'SALIDAS DE BERMEO',
});
// Lo que se ve en las capturas de la línea A3527 (Bilbao-Mungia-Bermeo).
const BLOCKS = [
    block('Negua', '01/09/2026', '30/06/2027', 'Invierno'),
    block('Gabonak (24-31)', '24/12/2026', '24/12/2026', 'Nochebuena y Nochevieja'),
    block('Gabonak (24-31)', '31/12/2026', '31/12/2026', 'Nochebuena y Nochevieja'),
    block('Gabonak (25 -1)', '25/12/2026', '25/12/2026', 'Navidad y Año Nuevo'),
    block('Gabonak (25 -1)', '01/01/2026', '01/01/2026', 'Navidad y Año Nuevo'),
    block('Bakarra', '26/12/2026', '30/12/2026', 'Verano y Navidad'),
    block('Bakarra', '01/08/2026', '31/08/2026', 'Verano y Navidad'),
    block('Udaldia 2025', '01/07/2025', '31/08/2025', 'Verano del año pasado'),
    block('Sin fechas', '', '', 'Siempre'),
];

const results = [];
const check = (name, ok, detail = '') => {
    let line = `MAL ${name}`;
    if (ok) line = `OK  ${name}`;
    if (!ok && detail) line += ` — ${detail}`;
    results.push(line);
};
const day = (year, month, date) => new Date(year, month - 1, date);
const titles = (periods) => periods.map((period) => period.season);

const autumn = schedulePeriods(BLOCKS, day(2026, 10, 31));
const find = (season) => autumn.find((period) => period.season === season);
check('el periodo de hoy va primero y marcado', autumn[0].season === 'Negua' && autumn[0].isCurrent === true, titles(autumn).join(', '));
check('después, por fecha de inicio, y los que no tienen fecha al final', titles(autumn).join('|') === 'Negua|Gabonak (24-31)|Gabonak (25 -1)|Bakarra|Sin fechas', titles(autumn).join(', '));
check('«Gabonak (24-31)»: una sola tarjeta con sus dos días', JSON.stringify(find('Gabonak (24-31)').dates) === JSON.stringify(['24/12/2026', '31/12/2026']), JSON.stringify(find('Gabonak (24-31)').dates));
check('«Gabonak (25 -1)»: sin el 01/01/2026, que ya pasó', JSON.stringify(find('Gabonak (25 -1)').dates) === JSON.stringify(['25/12/2026']), JSON.stringify(find('Gabonak (25 -1)').dates));
check('«Bakarra»: solo el tramo que viene, sin agosto', JSON.stringify(find('Bakarra').dates) === JSON.stringify(['26/12/2026 – 30/12/2026']), JSON.stringify(find('Bakarra').dates));
check('nada de años anteriores', !autumn.some((period) => period.season === 'Udaldia 2025'));
check('los textos de ida y vuelta son los de castellano', find('Negua').outbound === 'Invierno' && find('Negua').returnTrip === 'SALIDAS DE BERMEO');

const christmasEve = schedulePeriods(BLOCKS, day(2026, 12, 24));
check('el 24/12, «Gabonak (24-31)» es el de hoy', christmasEve.find((period) => period.isCurrent && period.season === 'Gabonak (24-31)') !== undefined);
const newYearsEve = schedulePeriods(BLOCKS, day(2026, 12, 31));
check('el 31/12 también: es el segundo día del mismo periodo', newYearsEve.some((period) => period.isCurrent && period.season === 'Gabonak (24-31)'));

const newYear = schedulePeriods(BLOCKS, day(2027, 1, 2));
check('al cambiar de año desaparece lo del anterior', titles(newYear).join('|') === 'Negua|Sin fechas', titles(newYear).join(', '));
check('el último día de un periodo todavía sale', schedulePeriods(BLOCKS, day(2026, 12, 30)).some((period) => period.season === 'Bakarra' && period.isCurrent));
check('sin bloques, no hay periodos', schedulePeriods([], day(2026, 10, 31)).length === 0);

console.log(results.join('\n'));
const bad = results.filter((line) => line.startsWith('MAL')).length;
console.log(`\nRESULTADO periodos del horario oficial: ${results.length - bad} OK, ${bad} MAL`);
process.exit(Number(bad > 0));
