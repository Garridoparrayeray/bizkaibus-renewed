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
            
            // distanciaKm (no dist_km) y redondeada a 2 decimales: mismo nombre y
            // formato que Models\Station::buildListItem(), que es lo que lee
            // renderList() en app.js.
            const withDist = data.map(s => ({ ...s, distanciaKm: Math.round(haversine(lat, lon, s.lat, s.lon) * 100) / 100 }))
                                 .filter(s => s.distanciaKm <= radius);

            if (sort === 'price') {
                withDist.sort((a, b) => (a.precios[fuel] || 999) - (b.precios[fuel] || 999));
            } else {
                withDist.sort((a, b) => a.distanciaKm - b.distanciaKm);
            }

            return { stations: withDist.slice(offset, offset + limit), total: withDist.length };
        },
        bbox: async ({ north, south, east, west, fuel }) => {
            const data = await loadData();
            const inBounds = data.filter(s => s.lat <= north && s.lat >= south && s.lon <= east && s.lon >= west);

            const stations = inBounds.map(s => {
                let precio = null;
                if (fuel && s.precios[fuel] !== undefined) {
                    precio = s.precios[fuel];
                }
                return {
                    ideess: s.ideess,
                    rotulo: s.rotulo,
                    lat: s.lat,
                    lon: s.lon,
                    is24h: !!s.is_24h,
                    precio,
                };
            });

            return { stations: fuel ? stations.filter(s => s.precio !== null) : stations };
        },
        search: async ({ q, lat, lon, sort = 'price', fuel = 'gasolina_95_e5', offset = 0, limit = 20 }) => {
            let data = await loadData();
            data = applyFilters(data, fuel);

            const nq = normalize(q);
            const rawQuery = (q || '').trim();
            const hasLocation = lat !== undefined && lat !== null && lon !== undefined && lon !== null;

            const matches = data.filter(s =>
                normalize(s.rotulo).includes(nq) ||
                normalize(s.municipio).includes(nq) ||
                normalize(s.localidad).includes(nq) ||
                normalize(s.direccion).includes(nq) ||
                (rawQuery !== '' && String(s.cp || '').startsWith(rawQuery))
            );

            function relevance(s) {
                const muni = normalize(s.municipio);
                const loc = normalize(s.localidad);
                if (muni === nq || loc === nq || String(s.cp || '') === rawQuery) return 0;
                if (muni.startsWith(nq) || loc.startsWith(nq)) return 1;
                if (rawQuery !== '' && String(s.cp || '').startsWith(rawQuery)) return 2;
                if (muni.includes(nq) || loc.includes(nq)) return 3;
                if (normalize(s.rotulo).includes(nq)) return 4;
                return 5;
            }

            matches.forEach(s => {
                s.relevance = relevance(s);
                s.distanciaKm = null;
                if (hasLocation) {
                    s.distanciaKm = Math.round(haversine(lat, lon, s.lat, s.lon) * 100) / 100;
                }
            });

            matches.sort((a, b) => {
                if (a.relevance !== b.relevance) return a.relevance - b.relevance;
                if (hasLocation && sort === 'distance') return a.distanciaKm - b.distanciaKm;
                return (a.precios[fuel] || 999) - (b.precios[fuel] || 999);
            });

            return { stations: matches.slice(offset, offset + limit), total: matches.length };
        },
        station: async (ideess) => {
            let data = await loadData();
            return data.find(s => String(s.ideess) === String(ideess));
        },

        suggestPlaces: async (q) => {
            const nq = normalize(q);
            if (!nq) return [];
            const data = await loadData();

            const municipios = new Map();
            const localidades = new Map();
            for (const s of data) {
                const muniNorm = normalize(s.municipio);
                if (s.municipio && muniNorm.startsWith(nq) && !municipios.has(muniNorm)) {
                    municipios.set(muniNorm, { label: s.municipio, sublabel: s.provincia });
                }
                const locNorm = normalize(s.localidad);
                if (s.localidad && locNorm.startsWith(nq) && locNorm !== muniNorm && !localidades.has(locNorm)) {
                    localidades.set(locNorm, { label: s.localidad, sublabel: `${s.municipio}, ${s.provincia}` });
                }
            }

            const byLabel = (a, b) => a.label.localeCompare(b.label, 'es');
            return [...municipios.values()].sort(byLabel)
                .concat([...localidades.values()].sort(byLabel))
                .slice(0, 8);
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
                if (e.status === 0) return { places: await OfflineEngine.suggestPlaces(q) };
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
