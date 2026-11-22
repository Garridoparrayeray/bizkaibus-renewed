// La analítica de Vercel recibe la dirección de cada página vista. El planificador escribe en ella el origen y el
// destino elegidos (de, a y sus _red, _id, _lat, _lon, _nombre…) y la hora: se quitan antes de mandarla, como el
// service worker al guardar la página (PLANNER_PARAMS), y también el fragmento, que lleva los viajes compartidos.
(() => {
    const PLANNER_PARAMS = /^(de|a|cuando|dia|hora|orden|viaje)(_|$)/;
    window.va = window.va || function () {
        (window.vaq = window.vaq || []).push(arguments);
    };
    window.va('beforeSend', (event) => {
        const url = new URL(event.url);
        Array.from(url.searchParams.keys()).filter((key) => PLANNER_PARAMS.test(key))
            .forEach((key) => url.searchParams.delete(key));
        url.hash = '';
        return Object.assign({}, event, { url: url.toString() });
    });
})();
