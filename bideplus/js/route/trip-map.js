// El mapa del viaje elegido: cada vehículo por su recorrido con el color de su red, lo andado por la calle, el origen
// y el destino, sobre el mapa base (js/route/basemap.js). Es un complemento de la lista de tramos, que dice lo mismo
// en texto: lo dibujado no llega a los lectores de pantalla. Lo dibuja el trabajador del planificador (trip-map).
import * as engine from '/planificador/pantallas/engine.js';
import { guardMap } from '/planificador/pantallas/map-guard.js';
import { t } from '/planificador/pantallas/lang.js';
import { MICRODEGREES } from '/planificador/pantallas/texts.js';
import { addBasemap, loadLeaflet, tokensOf } from './basemap.js';
import { colorOf, el } from './common.js';

const RIDE_WEIGHT = 6;
const CASING_WEIGHT = 9;
const WALK_WEIGHT = 4;
const WALK_DASH = '2 8';
const APPROXIMATE_DASH = '10 8';
// Margen al encuadrar; arriba a la izquierda, además, el sitio del control de zoom.
const FIT_PADDING = 24;
const ZOOM_CONTROL_ROOM = 44;
const COLORS = { walk: '--map-walk', casing: '--map-casing', origin: '--map-origin', target: '--map-target' };
// El mapa abierto (solo hay uno): de qué viaje es, su caja y cómo soltarlo. El directo repinta el viaje cada poco y
// el mismo viaje conserva su mapa.
const current = { key: '', element: null, close: null };

export const closeTripMap = () => {
    if (current.close !== null) {
        current.close();
    }
    Object.assign(current, { key: '', element: null, close: null });
};

const placeOf = (stop) => {
    if (!stop) {
        return null;
    }
    return { network: stop.network, id: stop.id, lat: stop.lat, lon: stop.lon };
};

const pointOf = (place) => {
    if (!place || !Number.isInteger(place.lat) || !Number.isInteger(place.lon)) {
        return null;
    }
    return { lat: place.lat, lon: place.lon };
};

// Lo que pide el trabajador: los tramos (con las paradas de cada vehículo, si prepare las trajo) y los extremos.
const payloadOf = (chosen, option, journey) => {
    const request = chosen.request || {};
    const aLegs = option.legs.map((leg) => {
        if (leg.kind !== 'ride') {
            return { kind: 'walk', from: placeOf(leg.from), to: placeOf(leg.to), meters: leg.meters, depart: leg.depart,
                arrive: leg.arrive };
        }
        let aCalls = [];
        if (journey) {
            aCalls = journey.journey.calls[journey.journey.legs.indexOf(leg)] || [];
        }
        return { kind: 'ride', network: leg.network, trip: leg.trip, from: placeOf(leg.from), to: placeOf(leg.to),
            calls: aCalls.map((call) => ({ lat: call.stop.lat, lon: call.stop.lon })) };
    });
    return { legs: aLegs, from: pointOf(request.from), to: pointOf(request.to),
        accessible: Boolean(request.accessible) };
};

// De qué viaje es un mapa: sus vehículos y sus paradas (no sus horas, que el directo cambia).
const keyOf = (option) => option.legs.map((leg) => [leg.kind, leg.trip || '', (leg.from && leg.from.id) || '',
    (leg.to && leg.to.id) || ''].join(':')).join('|');

// Un marcador redondo que no entra en el orden del tabulador ni responde al dedo; su color va en --dot.
const dot = (L, aPoint, sClass, sColor) => {
    const marker = L.marker(aPoint, {
        icon: L.divIcon({ className: 'route-map-dot ' + sClass, iconSize: null }), keyboard: false, interactive: false,
    });
    marker.on('add', () => marker.getElement().style.setProperty('--dot', sColor));
    return marker;
};

const drawLegs = (L, map, app, option, drawn, oColors) => {
    drawn.legs.forEach((shape, iLeg) => {
        const leg = option.legs[iLeg];
        if (shape.points.length < 2) {
            return;
        }
        if (shape.kind !== 'ride') {
            L.polyline(shape.points, { color: oColors.walk, weight: WALK_WEIGHT, dashArray: WALK_DASH, lineCap: 'round',
                interactive: false }).addTo(map);
            return;
        }
        const sColor = colorOf(app.networkOf(leg.network));
        let sDash = null;
        if (!shape.exact) {
            sDash = APPROXIMATE_DASH;
        }
        L.polyline(shape.points, { color: oColors.casing, weight: CASING_WEIGHT, interactive: false }).addTo(map);
        L.polyline(shape.points, { color: sColor, weight: RIDE_WEIGHT, dashArray: sDash, interactive: false })
            .addTo(map);
        dot(L, [leg.from.lat / MICRODEGREES, leg.from.lon / MICRODEGREES], 'route-map-dot--stop', sColor).addTo(map);
        dot(L, [leg.to.lat / MICRODEGREES, leg.to.lon / MICRODEGREES], 'route-map-dot--stop', sColor).addTo(map);
    });
    const aShown = drawn.legs.filter((shape) => shape.points.length >= 2);
    if (aShown.length > 0) {
        const aLast = aShown[aShown.length - 1].points;
        dot(L, aShown[0].points[0], 'route-map-dot--end', oColors.origin).addTo(map);
        dot(L, aLast[aLast.length - 1], 'route-map-dot--end', oColors.target).addTo(map);
    }
    return aShown.reduce((bounds, shape) => bounds.extend(shape.points), L.latLngBounds([]));
};

// Monta el mapa en canvas. Devuelve la función que lo suelta, o null si no hay nada que dibujar.
const mount = async (app, chosen, option, journey, frame, canvas) => {
    const [L, drawn] = await Promise.all([loadLeaflet(), engine.tripMap(payloadOf(chosen, option, journey))]);
    if (drawn === null || !canvas.isConnected) {
        return null;
    }
    const map = L.map(canvas, { zoomControl: false, attributionControl: true, keyboard: true });
    L.control.zoom({ zoomInTitle: t('planner.map.zoomIn'), zoomOutTitle: t('planner.map.zoomOut') }).addTo(map);
    map.attributionControl.setPrefix('');
    map.getPane('mapPane').setAttribute('aria-hidden', 'true');
    const bounds = drawLegs(L, map, app, option, drawn, tokensOf(canvas, COLORS));
    if (!bounds.isValid()) {
        map.remove();
        return null;
    }
    map.fitBounds(bounds, { paddingTopLeft: [FIT_PADDING + ZOOM_CONTROL_ROOM, FIT_PADDING],
        paddingBottomRight: [FIT_PADDING, FIT_PADDING], animate: false });
    await addBasemap(L, map, bounds);
    const guard = new AbortController();
    guardMap(map, frame, guard.signal);
    return () => {
        guard.abort();
        map.remove();
    };
};

// La caja del mapa de un viaje: la misma mientras sea el mismo viaje; si no se puede dibujar, desaparece.
export const tripMapNode = (app, chosen, option, journey) => {
    const sKey = keyOf(option);
    if (sKey === current.key) {
        return current.element;
    }
    closeTripMap();
    const box = el('section', 'route-trip-map');
    const frame = el('div', 'route-trip-map-frame');
    const canvas = el('div', 'route-trip-map-canvas');
    canvas.setAttribute('role', 'region');
    canvas.setAttribute('aria-label', t('planner.map.label'));
    frame.append(canvas);
    box.append(frame);
    Object.assign(current, { key: sKey, element: box });
    requestAnimationFrame(() => {
        mount(app, chosen, option, journey, frame, canvas).catch(() => null).then((close) => {
            if (current.element !== box) {
                if (close) {
                    close();
                }
                return;
            }
            if (close === null) {
                box.hidden = true;
                return;
            }
            current.close = close;
        });
    });
    return box;
};
