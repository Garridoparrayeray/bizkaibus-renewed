// Todas las peticiones AJAX a la API de Bide+ (/api/...), en un solo sitio: la red, los errores y el aviso de
// «sin conexión» (evento bb:api) se tratan igual en toda la app. Api.stop(id) pide a la red de la página;
// Api.forNetwork('metro').stop(id), a otra (el menú de inicio y las listas de favoritos de todas las apps).
const Api = (() => {
    function withNetwork(path, network) {
        if (network === BideNetworks.DEFAULT || !BideNetworks.exists(network)) {
            return path;
        }
        let separator = '?';
        if (path.includes('?')) separator = '&';
        return `${path}${separator}red=${network}`;
    }

    async function request(path, options, network) {
        let target = network;
        if (!target) target = window.__bbNetwork;
        let response;
        try {
            const init = { credentials: 'same-origin' };
            if (options.body) init.headers = { 'Content-Type': 'application/json' };
            response = await fetch(`/api${withNetwork(path, target)}`, Object.assign(init, options));
        } catch (networkError) {
            window.dispatchEvent(new CustomEvent('bb:api', { detail: { ok: false } }));
            throw networkError;
        }
        window.dispatchEvent(new CustomEvent('bb:api', { detail: { ok: true } }));
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            const error = new Error(data.error || `Error ${response.status}`);
            error.status = response.status;
            throw error;
        }
        return data;
    }

    // Los métodos de la API para una red (null: la de la página, mirada en cada petición).
    function methods(network) {
        const get = (path) => request(path, {}, network);
        const post = (path, body) => request(path, { method: 'POST', body: JSON.stringify(body) }, network);
        return {
            search: (q) => get(`/search?q=${encodeURIComponent(q)}`),
            nearby: (lat, lon) => get(`/nearby?lat=${lat}&lon=${lon}`),
            stop: (id) => get(`/stops/${id}`),
            stopDepartures: (id, limit = 8) => get(`/stops/${id}/departures?limit=${limit}`),
            pushKey: () => get('/push/key'),
            createArrivalAlert: (alert) => post('/push/arrival', alert),
            cancelArrivalAlert: (id, endpoint) => post(`/push/arrival/${id}/cancel`, { endpoint }),
            saveIncidentAlerts: (settings) => post('/push/incidents', settings),
            removeIncidentAlerts: (endpoint) => post('/push/incidents/remove', { endpoint }),
            lines: () => get('/lines'),
            line: (id) => get(`/lines/${id}`),
            lineScheduleText: (id) => get(`/lines/${id}/schedule-text`),
            timetable: (lineId, { date, hourFrom, hourTo, stopId }) => {
                const params = new URLSearchParams({ date, hourFrom, hourTo });
                if (stopId !== undefined && stopId !== null) params.set('stopId', stopId);
                return get(`/lines/${lineId}/timetable?${params}`);
            },
            vehicle: (tripKey, stopId) => {
                let path = `/vehicles/${tripKey}`;
                if (stopId) path += `?stopId=${encodeURIComponent(stopId)}`;
                return get(path);
            },
            tripStops: (tripKey, stopId) => get(`/trips/${tripKey}?stopId=${stopId}`),
            lineLive: (lineId) => get(`/lines/${lineId}/live`),
            alerts: (lineId, stopId) => {
                const params = new URLSearchParams();
                if (lineId) params.set('line', lineId);
                if (stopId) params.set('stop', stopId);
                const query = params.toString();
                if (query) return get(`/alerts?${query}`);
                return get('/alerts');
            },
        };
    }

    return { ...methods(null), forNetwork: methods };
})();
