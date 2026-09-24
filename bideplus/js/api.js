const Api = (() => {
    function withNetwork(path) {
        const net = window.__bbNetwork;
        if (net !== 'metro' && net !== 'euskotren' && net !== 'tranvia-bilbao' && net !== 'tranvia-vitoria') {
            return path;
        }
        const separator = path.includes('?') ? '&' : '?';
        return `${path}${separator}red=${net}`;
    }

    async function request(path, options = {}) {
        let response;
        try {
            response = await fetch(`/api${withNetwork(path)}`, {
                credentials: 'same-origin',
                headers: options.body ? { 'Content-Type': 'application/json' } : undefined,
                ...options,
            });
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
        vehicle: (tripKey) => request(`/vehicles/${tripKey}`),
        tripStops: (tripKey, stopId) => request(`/trips/${tripKey}?stopId=${stopId}`),
        lineLive: (lineId) => request(`/lines/${lineId}/live`),
        alerts: (lineId) => request(lineId ? `/alerts?line=${lineId}` : '/alerts'),
    };
})();
