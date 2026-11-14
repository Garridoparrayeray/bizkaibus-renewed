// Piezas que comparten las opciones, la pantalla del viaje y los filtros del planificador del inicio: nodos sin
// innerHTML, la pastilla de una línea, la etiqueta de color de una opción y los nombres de origen y destino.
import { inkOn } from '/planificador/pantallas/dom.js';
import { endNames, tightestSpare } from '/planificador/pantallas/journey.js';
import { LINK_STATES, TAGS, tagText, tightText } from '/planificador/pantallas/texts.js';
import { t } from '/planificador/pantallas/lang.js';

const SVG = 'http://www.w3.org/2000/svg';
const NEUTRAL_LINE = '#4A5650';
// Trazos de los iconos que monta el planificador (caja de 24 × 24, dibujados con línea).
const ICONS = {
    chevron: 'M6 9L12 15L18 9',
    walk: 'M13 4.5A1.8 1.8 0 1 0 13 4.49ZM13 8L10 13L13 16V21M10 13L7 15M13 9L16 12L19 12.5M10 16L8 21',
    transfer: 'M4 8H18L15 5M20 16H6L9 19',
    clock: 'M12 3A9 9 0 1 0 12 21A9 9 0 1 0 12 3ZM12 7V12L15 14',
    alert: 'M12 6V13M12 17.5V17.6',
};

export const el = (sTag, sClass, sText) => {
    const element = document.createElement(sTag);
    if (sClass) {
        element.className = sClass;
    }
    if (sText !== undefined && sText !== null) {
        element.textContent = sText;
    }
    return element;
};

export const byId = (sId) => document.getElementById(sId);

export const svgIcon = (sName, sClass) => {
    const svg = document.createElementNS(SVG, 'svg');
    const path = document.createElementNS(SVG, 'path');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('class', sClass);
    path.setAttribute('d', ICONS[sName]);
    svg.append(path);
    return svg;
};

// El color de una red («#RRGGBB») o un gris si no lo trae.
export const colorOf = (network) => {
    if (network && /^#[0-9a-fA-F]{6}$/.test(network.color)) {
        return network.color;
    }
    return NEUTRAL_LINE;
};

// La pastilla cuadrada de una línea, del color de su red y con la letra que más contrasta.
export const linePill = (sCode, network) => {
    const pill = el('span', 'route-line', sCode);
    pill.style.setProperty('--line', colorOf(network));
    pill.style.setProperty('--line-ink', inkOn(colorOf(network)));
    return pill;
};

// La etiqueta redonda de arriba de una opción: su texto y su tipo (good, yours, neutral, care o serious), que da el
// color. «Más rápido» es el nombre de la app para fastest; las demás, las del planificador.
const tagOf = (option, app) => {
    if (option.state && LINK_STATES[option.state]) {
        return { text: t(LINK_STATES[option.state].key), kind: LINK_STATES[option.state].kind };
    }
    if (!option.tag || !TAGS[option.tag]) {
        return null;
    }
    let sText = tagText(option.tag, option.tag_value, app.networkName);
    const iSpare = tightestSpare(option.legs || []);
    if (option.tag === 'fastest') {
        sText = I18n.t('route.tag.fastest');
    } else if (option.tag === 'tight_transfer' && iSpare !== null) {
        sText = tightText(iSpare);
    }
    return { text: sText, kind: TAGS[option.tag].kind };
};

export const tagPill = (sText, sKind) => {
    const pill = el('span', 'route-tag');
    pill.dataset.kind = sKind;
    if (sKind === 'serious') {
        pill.append(svgIcon('alert', 'route-tag-icon'));
    }
    pill.append(sText);
    return pill;
};

// Las etiquetas de una opción: la suya (o lo que ha pasado con ella, en un enlace recibido) y «Accesible».
export const optionTags = (option, app) => {
    const tags = el('span', 'route-tags');
    const tag = tagOf(option, app);
    if (tag) {
        tags.append(tagPill(tag.text, tag.kind));
    }
    if (option.accessible === true) {
        tags.append(tagPill(t('planner.card.accessible'), 'good'));
    }
    return tags;
};

// «Origen → destino» de un viaje: los de la búsqueda o, en un enlace recibido, la primera y la última parada.
export const namesOf = (legs, query) => {
    let sFrom = '';
    let sTo = '';
    if (query) {
        sFrom = query.from.name;
        sTo = query.to.name;
    }
    return endNames(legs, sFrom, sTo);
};

// Textos con singular y plural: { one, many }, con {n} y lo que se añada.
const COUNTS = {
    stops: ['route.ride.stops.one', 'route.ride.stops.many'],
    options: ['route.summary.one', 'route.summary.many'],
};

export const countText = (sCount, iCount, vars) => {
    let sKey = COUNTS[sCount][1];
    if (iCount === 1) {
        sKey = COUNTS[sCount][0];
    }
    return I18n.t(sKey, Object.assign({ n: iCount }, vars));
};

// Los tramos en vehículo de una opción.
export const ridesIn = (legs) => legs.filter((leg) => leg.kind === 'ride');
