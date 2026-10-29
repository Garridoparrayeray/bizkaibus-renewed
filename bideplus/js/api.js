const Api = (() => {
    function withNetwork(path) {
        const net = window.__bbNetwork;
        if (net === BideNetworks.DEFAULT || !BideNetworks.exists(net)) {
            return path;
        }
        let separator = '?';
        if (path.includes('?')) separator = '&';
        return `${path}${separator}red=${net}`;
    }

    async function request(path, options = {}) {
        let response;
        try {
            const init = { credentials: 'same-origin' };
            if (options.body) init.headers = { 'Content-Type': 'application/json' };
            response = await fetch(`/api${withNetwork(path)}`, Object.assign(init, options));
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

    return {
        search: (q) => request(`/search?q=${encodeURIComponent(q)}`),
        nearby: (lat, lon) => request(`/nearby?lat=${lat}&lon=${lon}`),
        stop: (id) => request(`/stops/${id}`),
        stopDepartures: (id, limit = 8) => request(`/stops/${id}/departures?limit=${limit}`),
        lines: () => request('/lines'),
        line: (id) => request(`/lines/${id}`),
        lineScheduleText: (id) => request(`/lines/${id}/schedule-text`),
        timetable: (lineId, { date, hourFrom, hourTo, stopId }) => {
            const params = new URLSearchParams({ date, hourFrom, hourTo });
            if (stopId !== undefined && stopId !== null) params.set('stopId', stopId);
            return request(`/lines/${lineId}/timetable?${params}`);
        },
        vehicle: (tripKey, stopId) => {
            let path = `/vehicles/${tripKey}`;
            if (stopId) path += `?stopId=${encodeURIComponent(stopId)}`;
            return request(path);
        },
        tripStops: (tripKey, stopId) => request(`/trips/${tripKey}?stopId=${stopId}`),
        lineLive: (lineId) => request(`/lines/${lineId}/live`),
        alerts: (lineId, stopId) => {
            const params = new URLSearchParams();
            if (lineId) params.set('line', lineId);
            if (stopId) params.set('stop', stopId);
            const query = params.toString();
            if (query) return request(`/alerts?${query}`);
            return request('/alerts');
        },
    };
})();
