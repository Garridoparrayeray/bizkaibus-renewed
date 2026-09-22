const OfflineEngine = (() => {
    let stationsCache = null;

    async function loadData() {
        if (!stationsCache) {
            const res = await fetch('/data/stations-lite.json');
            stationsCache = await res.json();
        }
        return stationsCache;
    }

    function haversine(lat1, lon1, lat2, lon2) {
        const p = 0.017453292519943295;
        const c = Math.cos;
        const a = 0.5 - c((lat2 - lat1) * p)/2 + c(lat1 * p) * c(lat2 * p) * (1 - c((lon2 - lon1) * p))/2;
        return 12742 * Math.asin(Math.sqrt(a));
    }

    function normalize(text) {
        if (!text) return '';
        return text.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase();
    }

    function applyFilters(stations, fuel) {
        return stations.filter(s => {
            if (fuel && s.precios[fuel] === undefined) return false;
            return true;
        });
    }

    return {
        near: async ({ lat, lon, radius = 10, sort = 'price', fuel = 'gasolina_95_e5', offset = 0, limit = 20 }) => {
            let data = await loadData();
            data = applyFilters(data, fuel);
            
            const withDist = data.map(s => ({ ...s, dist_km: haversine(lat, lon, s.lat, s.lon) }))
                                 .filter(s => s.dist_km <= radius);

            if (sort === 'price') {
                withDist.sort((a, b) => (a.precios[fuel] || 999) - (b.precios[fuel] || 999));
            } else {
                withDist.sort((a, b) => a.dist_km - b.dist_km);
            }

            return { data: withDist.slice(offset, offset + limit), total: withDist.length };
        },
        bbox: async ({ north, south, east, west, fuel }) => {
            let data = await loadData();
            data = applyFilters(data, fuel);
            return data.filter(s => s.lat <= north && s.lat >= south && s.lon <= east && s.lon >= west);
        },
        search: async ({ q, lat, lon, sort = 'price', fuel = 'gasolina_95_e5', offset = 0, limit = 20 }) => {
            let data = await loadData();
            data = applyFilters(data, fuel);
            
            const nq = normalize(q);
            const matches = data.filter(s => 
                normalize(s.rotulo).includes(nq) || 
                normalize(s.municipio).includes(nq) ||
                normalize(s.direccion).includes(nq)
            );

            if (lat !== undefined && lon !== undefined) {
                matches.forEach(s => s.dist_km = haversine(lat, lon, s.lat, s.lon));
                if (sort === 'distance') {
                    matches.sort((a, b) => a.dist_km - b.dist_km);
                } else {
                    matches.sort((a, b) => (a.precios[fuel] || 999) - (b.precios[fuel] || 999));
                }
            } else {
                matches.sort((a, b) => (a.precios[fuel] || 999) - (b.precios[fuel] || 999));
            }

            return { data: matches.slice(offset, offset + limit), total: matches.length };
        },
        station: async (ideess) => {
            let data = await loadData();
            return data.find(s => String(s.ideess) === String(ideess));
        }
    };
})();

const Api = (() => {
    async function request(path) {
        let response;
        try {
            response = await fetch('/api' + path, { credentials: 'same-origin' });
        } catch (e) {
            const error = new Error('Sin conexión a internet');
            error.status = 0;
            throw error;
        }
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            const error = new Error(data.error || 'Error ' + response.status);
            error.status = response.status;
            throw error;
        }
        return data;
    }

    return {
        near: async (params) => {
            const { lat, lon, radius, sort, fuel, open, offset, limit } = params;
            const p = new URLSearchParams({ lat, lon, radius, sort });
            if (fuel) p.set('fuel', fuel);
            if (open) p.set('open', open);
            if (offset) p.set('offset', offset);
            if (limit) p.set('limit', limit);
            try {
                return await request('/stations/near?' + p);
            } catch (e) {
                if (e.status === 0) return OfflineEngine.near(params);
                throw e;
            }
        },
        search: async (params) => {
            const { q, lat, lon, sort, fuel, open, offset, limit } = params;
            const p = new URLSearchParams({ q, sort });
            if (lat !== undefined && lat !== null) p.set('lat', lat);
            if (lon !== undefined && lon !== null) p.set('lon', lon);
            if (fuel) p.set('fuel', fuel);
            if (open) p.set('open', open);
            if (offset) p.set('offset', offset);
            if (limit) p.set('limit', limit);
            try {
                return await request('/stations/search?' + p);
            } catch (e) {
                if (e.status === 0) return OfflineEngine.search(params);
                throw e;
            }
        },
        suggestPlaces: async (q) => {
            try {
                return await request('/stations/suggest-places?q=' + encodeURIComponent(q));
            } catch (e) {
                if (e.status === 0) return { places: [] }; // No geocoding offline without full DB
                throw e;
            }
        },
        bbox: async (params) => {
            const { north, south, east, west, fuel, open } = params;
            const p = new URLSearchParams({ north, south, east, west });
            if (fuel) p.set('fuel', fuel);
            if (open) p.set('open', open);
            try {
                return await request('/stations/bbox?' + p);
            } catch (e) {
                if (e.status === 0) return OfflineEngine.bbox(params);
                throw e;
            }
        },
        station: async (ideess) => {
            try {
                return await request('/stations/' + ideess);
            } catch (e) {
                if (e.status === 0) {
                    const st = await OfflineEngine.station(ideess);
                    if (!st) throw new Error("No encontrada");
                    return { station: st, history: [] }; // No history offline
                }
                throw e;
            }
        },
        history: async (ideess, fuel, from, to, group = 'day') => {
            try {
                return await request('/stations/' + ideess + '/history?fuel=' + fuel + '&from=' + from + '&to=' + to + '&group=' + group);
            } catch (e) {
                if (e.status === 0) return [];
                throw e;
            }
        },
        zoneComparison: async (ideess, fuel) => request('/stations/' + ideess + '/zone-comparison?fuel=' + fuel),
        nationalStats: async (fuel, from, to, group = 'day') => request('/stats/national?fuel=' + fuel + '&from=' + from + '&to=' + to + '&group=' + group),
        statsByFuel: async (from, to, group = 'day') => request('/stats/by-fuel?from=' + from + '&to=' + to + '&group=' + group),
        statsByProvince: async (fuel) => request('/stats/by-province?fuel=' + fuel),
        priceDistribution: async (fuel) => request('/stats/price-distribution?fuel=' + fuel),
    };
})();
