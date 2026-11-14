// La tarjeta «¿A dónde vas?» del inicio: lo escrito en origen y destino, cuándo, el día y la hora van al planificador
// (/planificador?de_texto=…&a_texto=…&cuando=…&dia=…&hora=…), que abre directamente sus opciones. «Tu ubicación» es
// el lado vacío que lo dice; con «No usar mi ubicación» (Ajustes) no hay ninguno.
(function () {
    'use strict';

    const form = document.querySelector('[data-planner-card]');
    if (!form) return;
    const from = form.querySelector('[data-planner-from]');
    const to = form.querySelector('[data-planner-to]');
    const here = form.querySelector('[data-planner-here]');
    const time = form.querySelector('[data-planner-time]');
    const day = form.querySelector('[data-planner-day]');
    const hour = form.querySelector('[data-planner-hour]');
    const TIME_ZONE = 'Europe/Madrid';
    const DAYS_AHEAD = 6;
    const ROUND_MINUTES = 5;
    const MILLIS_PER_DAY = 86400000;
    const MINUTES_PER_HOUR = 60;
    const MINUTES_PER_DAY = 1440;
    // De qué lado está «Tu ubicación»: 'from', 'to' o '' si la ubicación está apagada en Ajustes.
    let hereSide = 'from';

    function locationOff() {
        try {
            return localStorage.getItem('bide_location_off') === '1';
        } catch (e) {
            return false;
        }
    }

    // La fecha y la hora de aquí (los horarios son de Euskal Herria aunque el móvil tenga otra zona).
    function nowHere() {
        const parts = {};
        new Intl.DateTimeFormat('en-GB', {
            timeZone: TIME_ZONE, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
        }).formatToParts(new Date()).forEach(function (part) {
            parts[part.type] = Number(part.value);
        });
        return { day: Date.UTC(parts.year, parts.month - 1, parts.day), minutes: parts.hour * MINUTES_PER_HOUR + parts.minute };
    }

    function pad(number) {
        return String(number).padStart(2, '0');
    }

    function placeholderOf(side) {
        if (side === hereSide) return I18n.t('menu.planner.from');
        if (side === 'from') return I18n.t('menu.planner.fromPlaceholder');
        return I18n.t('menu.planner.to');
    }

    function paintPlaceholders() {
        from.placeholder = placeholderOf('from');
        to.placeholder = placeholderOf('to');
    }

    // Hoy (sin fecha: el planificador entiende hoy), mañana y los días siguientes («lunes 16»): los nombres de los días
    // salen de los textos de la app (app.day.1 es el lunes), que tienen todos sus idiomas.
    function fillDays() {
        const today = nowHere().day;
        const chosen = day.value;
        day.replaceChildren();
        for (let i = 0; i <= DAYS_AHEAD; i++) {
            const date = new Date(today + i * MILLIS_PER_DAY);
            const option = document.createElement('option');
            option.value = '';
            option.textContent = I18n.t('app.day.' + (((date.getUTCDay() + 6) % 7) + 1)) + ' ' + date.getUTCDate();
            if (i === 0) option.textContent = I18n.t('menu.planner.today');
            if (i === 1) option.textContent = I18n.t('menu.planner.tomorrow');
            if (i > 0) option.value = date.toISOString().slice(0, 10);
            day.appendChild(option);
        }
        day.value = chosen;
    }

    // Con «Salir a» o «Llegar a», el día y la hora; sin hora, la de ahora redondeada a 5 minutos.
    function showTime() {
        const when = form.querySelector('input[name="cuando"]:checked').value;
        time.hidden = when === 'ahora';
        if (time.hidden || hour.value !== '') return;
        const minutes = Math.min(Math.ceil(nowHere().minutes / ROUND_MINUTES) * ROUND_MINUTES, MINUTES_PER_DAY - ROUND_MINUTES);
        hour.value = pad(Math.floor(minutes / MINUTES_PER_HOUR)) + ':' + pad(minutes % MINUTES_PER_HOUR);
    }

    function swap() {
        const typed = from.value;
        from.value = to.value;
        to.value = typed;
        if (hereSide === 'from') hereSide = 'to';
        else if (hereSide === 'to') hereSide = 'from';
        paintPlaceholders();
    }

    // El origen vacío ya es «Tu ubicación» para el planificador; el destino vacío lo es solo si se ha cambiado aquí. Un
    // lado vacío que no es «Tu ubicación» se pide aquí mismo.
    function submit(event) {
        here.disabled = !(hereSide === 'to' && to.value.trim() === '');
        let missing = null;
        if (from.value.trim() === '' && hereSide !== 'from') missing = from;
        else if (to.value.trim() === '' && hereSide !== 'to') missing = to;
        if (missing) {
            event.preventDefault();
            missing.focus();
        }
    }

    if (locationOff()) hereSide = '';
    paintPlaceholders();
    fillDays();
    showTime();
    form.querySelectorAll('input[name="cuando"]').forEach(function (radio) {
        radio.addEventListener('change', showTime);
    });
    form.querySelector('[data-planner-swap]').addEventListener('click', swap);
    form.addEventListener('submit', submit);
    window.addEventListener('bide:lang', function () {
        paintPlaceholders();
        fillDays();
    });
})();
