const Api = (() => {
    async function request(path) {
        const response = await fetch(`/api${path}`, { credentials: 'same-origin' });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            const error = new Error(data.error || `Error ${response.status}`);
            error.status = response.status;
            throw error;
        }
        return data;
    }

    return {
        near: ({ lat, lon, radius, sort, fuel, open, offset, limit }) => {
            const params = new URLSearchParams({ lat, lon, radius, sort });
            if (fuel) params.set('fuel', fuel);
            if (open) params.set('open', open);
            if (offset) params.set('offset', offset);
            if (limit) params.set('limit', limit);
            return request(`/stations/near?${params}`);
        },
        search: ({ q, lat, lon, sort, fuel, open, offset, limit }) => {
            const params = new URLSearchParams({ q, sort });
            if (lat !== undefined && lat !== null) params.set('lat', lat);
            if (lon !== undefined && lon !== null) params.set('lon', lon);
            if (fuel) params.set('fuel', fuel);
            if (open) params.set('open', open);
            if (offset) params.set('offset', offset);
            if (limit) params.set('limit', limit);
            return request(`/stations/search?${params}`);
        },
        bbox: ({ north, south, east, west, fuel, open }) => {
            const params = new URLSearchParams({ north, south, east, west });
            if (fuel) params.set('fuel', fuel);
            if (open) params.set('open', open);
            return request(`/stations/bbox?${params}`);
        },
        station: (ideess) => request(`/stations/${ideess}`),
        history: (ideess, fuel, from, to, group = 'day') => request(`/stations/${ideess}/history?fuel=${fuel}&from=${from}&to=${to}&group=${group}`),
        zoneComparison: (ideess, fuel) => request(`/stations/${ideess}/zone-comparison?fuel=${fuel}`),
        nationalStats: (fuel, from, to, group = 'day') => request(`/stats/national?fuel=${fuel}&from=${from}&to=${to}&group=${group}`),
        statsByFuel: (from, to, group = 'day') => request(`/stats/by-fuel?from=${from}&to=${to}&group=${group}`),
        statsByProvince: (fuel) => request(`/stats/by-province?fuel=${fuel}`),
        priceDistribution: (fuel) => request(`/stats/price-distribution?fuel=${fuel}`),
    };
})();
