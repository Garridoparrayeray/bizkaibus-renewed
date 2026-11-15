// El fondo del mapa del viaje: el mapa base propio de Bizkaia (PMTiles vectorial con lib/protomaps-leaflet) con los
// colores de la página, claros u oscuros según el sistema. Fuera de su recuadro, o si no carga, el de siempre: las
// teselas de OpenStreetMap del planificador (que, si fallan, dibuja las calles del grafo a pie).
import { addBase } from '/planificador/pantallas/map-base.js';

const LEAFLET_SCRIPT = '/lib/leaflet/leaflet.js';
const LEAFLET_STYLE = '/lib/leaflet/leaflet.css';
const PROTOMAPS_SCRIPT = '/lib/protomaps-leaflet/protomaps-leaflet.js';
const BASEMAP_URL = '/map-data/bizkaia.pmtiles';
const ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" '
    + 'rel="noopener noreferrer">OpenStreetMap</a>';
const DARK = '(prefers-color-scheme: dark)';
const MAX_ZOOM = 19;
// Desde qué zoom de pantalla salen los edificios y los nombres de las calles.
const BUILDINGS_ZOOM = 15;
const STREET_NAMES_ZOOM = 16;
const LABEL_HALO = 3;
const LABEL_SIZE = 12;
const LABEL_WEIGHT = 700;
// Los nombres de los lugares, de más a menos importantes (se colocan en ese orden y no se pisan): su tamaño de letra
// lejos y cerca, y desde qué zoom de pantalla se ven así de grandes. Van a un lado del punto, no encima de su parada.
const PLACE_KINDS = [
    [['city'], 13, 15], [['town'], 12, 14], [['village'], 11, 13], [['suburb', 'neighbourhood'], 11, 12],
];
const PLACE_NEAR_ZOOM = 12;
const PLACE_OFFSET = 10;
const PLACE_SIDES = [1, 5, 3, 7];
// Los nombres van en su propio panel, con su halo encima de los recorridos (overlayPane, 400) y debajo de las paradas
// del viaje (markerPane, 600), que no se tapan.
const LABEL_PANE = 'routeLabels';
const LABEL_PANE_Z = 450;
const STEPS_DASH = [1, 1.5];
const PATH_DASH = [3, 2];
// El ancho de cada clase de calle (en px) a zoom 12, 15 y 18; la base de la curva, como en Protomaps.
const WIDTH_BASE = 1.6;
const WIDTHS = {
    major: [1.5, 6, 18], medium: [1, 4.5, 14], minor: [0, 2.5, 10], path: [0, 1, 3], steps: [0, 3, 7],
    rail: [0.5, 1.5, 3],
};
const WIDTH_ZOOMS = [12, 15, 18];
const CASING_EXTRA = 1.5;
const KINDS = {
    major: ['motorway', 'trunk', 'primary', 'motorway_link', 'trunk_link', 'primary_link'],
    medium: ['secondary', 'tertiary', 'secondary_link', 'tertiary_link'],
    minor: ['residential', 'unclassified', 'living_street', 'service', 'road', 'pedestrian'],
    path: ['footway', 'path', 'cycleway', 'bridleway', 'track', 'corridor'],
    steps: ['steps'],
    rail: ['rail', 'subway', 'light_rail', 'tram', 'narrow_gauge', 'funicular'],
    water: ['river', 'canal'],
    green: ['park', 'garden', 'pitch', 'golf_course', 'playground', 'grass', 'cemetery'],
    wood: ['forest', 'wood'],
    urban: ['residential', 'industrial', 'commercial', 'retail', 'railway'],
    sand: ['beach', 'sand'],
};
const COLORS = {
    land: '--map-land', water: '--map-water', green: '--map-green', wood: '--map-wood', urban: '--map-urban',
    sand: '--map-sand', building: '--map-building', road: '--map-road', casing: '--map-road-casing',
    path: '--map-path', steps: '--map-steps', rail: '--map-rail', label: '--map-label', halo: '--map-label-halo',
};
const loading = { scripts: new Map(), basemap: null };

const addStyle = (sHref) => {
    if (document.querySelector('link[href="' + sHref + '"]')) {
        return;
    }
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = sHref;
    document.head.append(link);
};

// Un script una vez; si no carga, falla (y la próxima vez se vuelve a intentar).
const loadScript = (sSrc) => {
    if (!loading.scripts.has(sSrc)) {
        loading.scripts.set(sSrc, new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = sSrc;
            script.addEventListener('load', resolve);
            script.addEventListener('error', () => {
                loading.scripts.delete(sSrc);
                script.remove();
                reject(new Error(sSrc));
            });
            document.head.append(script);
        }));
    }
    return loading.scripts.get(sSrc);
};

export const loadLeaflet = async () => {
    addStyle(LEAFLET_STYLE);
    await loadScript(LEAFLET_SCRIPT);
    return window.L;
};

// La librería y la cabecera del PMTiles (su recuadro): { pmtiles, header }. Si algo falla, se reintenta otra vez.
const loadBasemap = () => {
    if (loading.basemap === null) {
        loading.basemap = loadScript(PROTOMAPS_SCRIPT).then(async () => {
            const source = new window.protomapsL.PmtilesSource(BASEMAP_URL, true);
            return { pmtiles: source.p, header: await source.p.getHeader() };
        });
        loading.basemap.catch(() => {
            loading.basemap = null;
        });
    }
    return loading.basemap;
};

const covers = (header, bounds) => bounds.getWest() >= header.minLon && bounds.getEast() <= header.maxLon
    && bounds.getSouth() >= header.minLat && bounds.getNorth() <= header.maxLat;

// Los colores de la página para un elemento: { nombre: valor } de los tokens { nombre: '--token' }.
export const tokensOf = (element, oTokens) => {
    const style = getComputedStyle(element);
    const oColors = {};
    Object.entries(oTokens).forEach(([sName, sToken]) => {
        oColors[sName] = style.getPropertyValue(sToken).trim();
    });
    return oColors;
};

const kindIn = (aKinds) => (iZoom, feature) => aKinds.includes(feature.props.kind);

const widthOf = (sClass, dExtra) => window.protomapsL.exp(WIDTH_BASE,
    WIDTH_ZOOMS.map((iZoom, i) => [iZoom, WIDTHS[sClass][i] + dExtra]));

const area = (sLayer, sFill, aKinds) => {
    const rule = { dataLayer: sLayer, symbolizer: new window.protomapsL.PolygonSymbolizer({ fill: sFill }) };
    if (aKinds) {
        rule.filter = kindIn(aKinds);
    }
    return rule;
};

// Una línea de las de kinds con el ancho de una clase de WIDTHS (más extra, para el borde).
const line = (sLayer, aKinds, sWidth, oOptions) => ({
    dataLayer: sLayer, filter: kindIn(aKinds),
    symbolizer: new window.protomapsL.LineSymbolizer(Object.assign({ width: widthOf(sWidth, 0), lineCap: 'round' },
        oOptions)),
});

// A trazos (protomaps-leaflet los pinta con dashColor y dashWidth).
const dashed = (aKinds, sWidth, sColor, aDash) => line('roads', aKinds, sWidth,
    { color: sColor, dash: aDash, dashColor: sColor, dashWidth: widthOf(sWidth, 0), lineCap: 'butt' });

// Lo que se pinta, de abajo arriba: agua y suelos, edificios, ferrocarril, calles con su borde y, encima, caminos
// peatonales y escaleras (a trazos) para que se vea por dónde se anda.
const paintRules = (oColors) => {
    const aRoads = ['minor', 'medium', 'major'];
    const aCasings = aRoads.map((sClass) => line('roads', KINDS[sClass], sClass,
        { color: oColors.casing, width: widthOf(sClass, CASING_EXTRA) }));
    const aFills = aRoads.map((sClass) => line('roads', KINDS[sClass], sClass, { color: oColors.road }));
    return [
        area('landuse', oColors.urban, KINDS.urban), area('landuse', oColors.wood, KINDS.wood),
        area('landuse', oColors.green, KINDS.green), area('landuse', oColors.sand, KINDS.sand),
        area('water', oColors.water), line('waterways', KINDS.water, 'minor', { color: oColors.water }),
        Object.assign(area('buildings', oColors.building), { minzoom: BUILDINGS_ZOOM }),
        line('rail', KINDS.rail, 'rail', { color: oColors.rail }),
        ...aCasings, ...aFills,
        dashed(KINDS.path, 'path', oColors.path, PATH_DASH), dashed(KINDS.steps, 'steps', oColors.steps, STEPS_DASH),
    ];
};

// Entre los de una clase, primero los de más población (si el mapa base la trae).
const byPopulation = (a, b) => (Number(b.population) || 0) - (Number(a.population) || 0);

const placeRule = ([aKinds, iFar, iNear], oColors, sFont) => ({
    dataLayer: 'places', filter: kindIn(aKinds), sort: byPopulation,
    symbolizer: new window.protomapsL.OffsetTextSymbolizer({ fill: oColors.label, stroke: oColors.halo,
        width: LABEL_HALO, fontFamily: sFont, fontWeight: LABEL_WEIGHT, offsetX: PLACE_OFFSET, offsetY: PLACE_OFFSET,
        placements: PLACE_SIDES, fontSize: window.protomapsL.step(iFar, [[PLACE_NEAR_ZOOM, iNear]]) }),
});

const labelRules = (oColors, sFont) => [
    ...PLACE_KINDS.map((place) => placeRule(place, oColors, sFont)),
    { dataLayer: 'roads', minzoom: STREET_NAMES_ZOOM, symbolizer: new window.protomapsL.LineLabelSymbolizer({
        fill: oColors.label, stroke: oColors.halo, width: LABEL_HALO, fontFamily: sFont, fontSize: LABEL_SIZE,
        fontWeight: LABEL_WEIGHT }) },
];

// El fondo (sin nombres) y, en su panel, los nombres: [fondo, nombres].
const vectorLayers = (map, basemap) => {
    const oColors = tokensOf(map.getContainer(), COLORS);
    const common = { url: basemap.pmtiles, maxDataZoom: basemap.header.maxZoom, maxZoom: MAX_ZOOM,
        bounds: [[basemap.header.minLat, basemap.header.minLon], [basemap.header.maxLat, basemap.header.maxLon]] };
    const base = window.protomapsL.leafletLayer(Object.assign({ attribution: ATTRIBUTION, backgroundColor: oColors.land,
        paintRules: paintRules(oColors), labelRules: [] }, common));
    const labels = window.protomapsL.leafletLayer(Object.assign({ attribution: '', pane: LABEL_PANE, paintRules: [],
        labelRules: labelRules(oColors, getComputedStyle(document.body).fontFamily) }, common));
    return [base, labels];
};

const labelPane = (map) => {
    if (!map.getPane(LABEL_PANE)) {
        const pane = map.createPane(LABEL_PANE);
        pane.style.zIndex = String(LABEL_PANE_Z);
        pane.style.pointerEvents = 'none';
    }
};

// Pone el fondo de un mapa cuyo viaje ocupa bounds; si cambia el tema del sistema, se vuelve a pintar con sus colores.
export const addBasemap = async (L, map, bounds) => {
    const basemap = await loadBasemap().catch(() => null);
    if (!basemap || !covers(basemap.header, bounds)) {
        addBase(L, map, bounds);
        return;
    }
    labelPane(map);
    const layer = { current: vectorLayers(map, basemap) };
    layer.current.forEach((item) => item.addTo(map));
    const dark = window.matchMedia(DARK);
    const repaint = () => {
        layer.current.forEach((item) => map.removeLayer(item));
        layer.current = vectorLayers(map, basemap);
        layer.current.forEach((item) => item.addTo(map));
    };
    dark.addEventListener('change', repaint);
    map.on('unload', () => dark.removeEventListener('change', repaint));
};
