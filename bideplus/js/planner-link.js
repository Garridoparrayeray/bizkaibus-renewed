// Las direcciones del planificador desde una parada de la app (su página y el mapa de la red): «Cómo llegar aquí» la
// pone de destino (sale de «Tu ubicación») y «Salir desde aquí», de origen. Es la forma que lee planificador/pantallas.
(function (global) {
    'use strict';

    const ROLES = { to: 'a', from: 'de' };

    function url(role, network, stopId, name) {
        const prefix = ROLES[role];
        const params = new URLSearchParams();
        params.set(prefix, 'parada');
        params.set(prefix + '_red', network);
        params.set(prefix + '_id', String(stopId));
        params.set(prefix + '_nombre', name);
        return '/planificador?' + params.toString();
    }

    global.PlannerLink = { url: url };
})(window);
