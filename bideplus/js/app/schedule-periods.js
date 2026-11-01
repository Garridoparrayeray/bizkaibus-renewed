// Periodos del horario oficial de Bizkaibus: junta los días sueltos de un periodo y deja el de hoy y los que vienen.

// Las claves vienen en euskera y castellano: se usan las de castellano.
export function scheduleFields(block) {
    const fields = { season: '', from: '', to: '', outbound: '', returnTrip: '' };
    for (const [key, value] of Object.entries(block)) {
        if (!value) continue;
        const name = key.toUpperCase();
        if (name.includes('EU') && !name.includes('CAS')) continue;
        if (name.includes('TEMPORADA')) {
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
        groups.get(key).ranges.push({ from, to, fromText: fields.from, toText: fields.to });
    }
    const periods = [];
    for (const { fields, ranges } of groups.values()) {
        ranges.sort((first, second) => compareStart({ start: first.from }, { start: second.from }));
        periods.push({
            season: fields.season,
            outbound: fields.outbound,
            returnTrip: fields.returnTrip,
            dates: ranges.map(rangeText).filter(Boolean),
            isCurrent: ranges.some((range) => range.from !== null && range.from <= today && today <= range.to),
            start: ranges[0].from,
        });
    }
    periods.sort((first, second) => Number(second.isCurrent) - Number(first.isCurrent) || compareStart(first, second));
    return periods;
}
