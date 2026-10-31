// Periodos del horario oficial en texto de Bizkaibus («Ver horario oficial»).
// El operador publica cada día festivo como un bloque suelto («Gabonak (24-31)»: uno el 24/12 y otro el 31/12) y deja
// bloques de temporadas que ya pasaron. Aquí se juntan en una sola tarjeta los días de un mismo periodo y solo quedan
// el de hoy y los que vienen: lo que ya terminó no sale, así que al cambiar de año desaparece lo del anterior.

// Campos de un bloque del operador: temporada, desde, hasta, ida y vuelta. Las claves vienen en euskera y castellano:
// se usan las de castellano.
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

// «dd/mm/aaaa» → fecha a medianoche, o null si no tiene esa forma.
function parseDate(text) {
    const match = /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/.exec(text || '');
    if (!match) return null;
    return new Date(Number(match[3]), Number(match[2]) - 1, Number(match[1]));
}

// Un día suelto se escribe una vez; un tramo, «desde – hasta».
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

// Los periodos que enseñar a fecha de hoy: el vigente primero y después por fecha de inicio. Un bloque sin fechas no
// se sabe cuándo vale, así que se enseña.
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
