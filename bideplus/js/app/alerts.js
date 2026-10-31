// Lo propio de la app de cada red en los avisos de incidencias: el título del menú lateral según la red y, al volver a
// la pestaña, las salidas al día. Los avisos en sí (interruptor en Ajustes, push y comprobación) están en
// js/incident-alerts.js, que comparten el menú de inicio y las apps.
import { FEATURES, state } from './core.js';
import { loadDepartures } from './stop.js';

export { syncAlertFavorites } from '../incident-alerts.js';

function setIncidentsHeading(key) {
    document.querySelector('[data-i18n="app.myLinesIncidents"]').setAttribute('data-i18n', key);
}

export function setUpAlerts() {
    document.addEventListener('visibilitychange', () => {
        // Una pestaña en segundo plano congela el temporizador: al volver, las salidas se actualizan ya.
        if (document.visibilityState === 'visible' && state.currentStop && state.departuresRefreshTimer) loadDepartures();
    });

    if (FEATURES.alerts === 'stops') setIncidentsHeading('app.myStopsIncidents');
    if (FEATURES.alerts === 'network') setIncidentsHeading('app.myFavoritesIncidents');
}
