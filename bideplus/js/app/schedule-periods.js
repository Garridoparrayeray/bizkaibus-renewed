// Periodos del horario oficial: junta los días sueltos de un periodo y deja el de hoy y los que vienen. Los de Bizkaibus
// son el texto del operador; los de las demás redes salen de su GTFS (api/Services/GtfsScheduleText.php) y traen el
// nombre de los días como clave de traducción y cuál es el de hoy (ACTUAL).

// Las claves vienen en euskera y castellano: se usan las de castellano.
export function scheduleFields(block) {
    const fields = { season: '', from: '', to: '', outbound: '', returnTrip: '', current: '' };
    for (const [key, value] of Object.entries(block)) {
        if (!value) continue;
        const name = key.toUpperCase();
        if (name.includes('EU') && !name.includes('CAS')) continue;
        if (name === 'ACTUAL') {
            fields.current = value;
        } else if (name.includes('TEMPORADA')) {
            fields.season = value;
        } else if (name.includes('DESDE')) {
            fields.from = value;
        } else if (name.includes('HASTA')) {
            fields.to = value;
        } else if (name.includes('CAS') && (name.includes('JOAN') || name.includes('IDA'))) {
            fields.outbound = value;
        } else if (name.includes('CAS') && (name.includes('ETORRI') || name.includes('VUELTA'))) {
            fields.returnTrip = value;
        }
    }
    return fields;
}

function parseDate(text) {
    const match = /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/.exec(text || '');
    if (!match) return null;
    return new Date(Number(match[3]), Number(match[2]) - 1, Number(match[1]));
}

function rangeText(range) {
    if (range.fromText && range.toText && range.fromText !== range.toText) return `${range.fromText} – ${range.toText}`;
    if (range.fromText) return range.fromText;
    return range.toText;
}

// Los periodos sin fecha van al final.
function compareStart(first, second) {
    if (first.start === null && second.start === null) return 0;
    if (first.start === null) return 1;
    if (second.start === null) return -1;
    return first.start - second.start;
}

// Hoy está en el periodo; si el bloque dice si es el de hoy (ACTUAL), manda eso.
function isCurrentRange(range, today) {
    if (range.current !== '') return range.current === '1';
    return range.from !== null && range.from <= today && today <= range.to;
}

// El nombre de los días: el texto del operador tal cual, o una clave de traducción («i18n:app.days.weekdays») o los
// días de la semana («i18n-days:1,2,3,4»: de lunes a jueves; si no son seguidos, uno detrás de otro).
export function seasonLabel(season) {
    if (season.startsWith('i18n:')) return I18n.t(season.slice(5));
    if (!season.startsWith('i18n-days:')) return season;
    const days = season.slice(10).split(',').map(Number);
    const names = days.map((day) => I18n.t(`app.day.${day}`));
    const consecutive = days.every((day, i) => i === 0 || day === days[i - 1] + 1);
    let label = names.join(', ');
    if (consecutive && days.length > 2) label = I18n.t('app.days.range', { from: names[0], to: names[names.length - 1] });
    // En castellano y en euskera los días van en minúscula: solo la primera letra del nombre, en mayúscula.
    return label.charAt(0).toUpperCase() + label.slice(1);
}

// El vigente primero y después por fecha de inicio. Un bloque sin fechas se enseña siempre.
export function schedulePeriods(blocks, today) {
    const groups = new Map();
    for (const block of blocks) {
        const fields = scheduleFields(block);
        let from = parseDate(fields.from);
        let to = parseDate(fields.to);
        if (from === null) from = to;
        if (to === null) to = from;
        if (to !== null && to < today) continue;
        const key = [fields.season, fields.outbound, fields.returnTrip].join('\n');
        if (!groups.has(key)) groups.set(key, { fields, ranges: [] });
        groups.get(key).ranges.push({ from, to, fromText: fields.from, toText: fields.to, current: fields.current });
    }
    const periods = [];
    for (const { fields, ranges } of groups.values()) {
        ranges.sort((first, second) => compareStart({ start: first.from }, { start: second.from }));
        periods.push({
            season: fields.season,
            outbound: fields.outbound,
            returnTrip: fields.returnTrip,
            dates: ranges.map(rangeText).filter(Boolean),
            isCurrent: ranges.some((range) => isCurrentRange(range, today)),
            start: ranges[0].from,
        });
    }
    periods.sort((first, second) => Number(second.isCurrent) - Number(first.isCurrent) || compareStart(first, second));
    return periods;
}
