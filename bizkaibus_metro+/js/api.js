const Api = (() => {
    function withNetwork(path) {
        if (window.__bbNetwork !== 'metro') {
            return path;
        }
        const separator = path.includes('?') ? '&' : '?';
        return `${path}${separator}red=metro`;
    }

    async function request(path, options = {}) {
        const response = await fetch(`/api${withNetwork(path)}`, {
            credentials: 'same-origin',
            headers: options.body ? { 'Content-Type': 'application/json' } : undefined,
            ...options,
        });
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
