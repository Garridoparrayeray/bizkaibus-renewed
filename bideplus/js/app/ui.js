// Piezas de interfaz comunes: escapar HTML, textos de estado y retraso, y botones de resultado.
// Los textos de los operadores (nombres, destinos, avisos) nunca se insertan como HTML sin escapar.
export function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch]);
}

export function debounce(fn, delayMs) {
    let timer;
    return (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn(...args), delayMs);
    };
}

// Servicio reducido de la red (js/app/reduced.js): hoy, lo que va solo con su horario en sus líneas puede no pasar.
let reduced = null;

export function setReducedToday(value) {
    reduced = value;
}

// Una salida de hoy (no de otro día ni la primera de mañana) sin directo en una línea con servicio reducido.
function mayNotRun(departure) {
    if (!reduced || !reduced.today || !departure || departure.tomorrow || departure.status !== 'scheduled') return false;
    return !Array.isArray(reduced.lines) || reduced.lines.map(String).includes(String(departure.lineId));
}

// Cuánto falta, para las listas: «5 min» o, si hoy ya no sale ninguna más, «mañana 07:00».
export function departureWhen(departure) {
    if (departure.tomorrow) return I18n.t('app.tomorrowAt', { time: departure.scheduledTime });
    if (mayNotRun(departure)) return `${Math.max(departure.etaMinutes, 0)} min · ${I18n.t('app.list.mayNotRun')}`;
    return `${Math.max(departure.etaMinutes, 0)} min`;
}

function noData(busKey, trainKey) {
    const network = window.BideNetworks?.get(window.__bbNetwork);
    if (network && ['train', 'metro'].includes(network.kind)) return I18n.t(trainKey);
    return I18n.t(busKey);
}

// atOrigin: el bus espera en la primera parada de su viaje (el motor lo sitúa allí antes de su hora de salida).
export function liveBadgeText(status, etaMinutes, atOrigin = false) {
    if (status === 'departed') return I18n.t('app.badge.leaving');
    if (status === 'estimated') return I18n.t('app.badge.estimated');
    if (status === 'likely_departed') return noData('app.badge.likelyDeparted', 'app.badge.likelyDepartedTrain');
    if (status === 'cancelled') return I18n.t('app.badge.cancelled');
    if (status !== 'live') return I18n.t('app.badge.scheduled');
    if (etaMinutes <= 0 || atOrigin) return I18n.t('app.badge.atStop');
    if (etaMinutes <= 3) return I18n.t('app.badge.arriving');
    return I18n.t('app.badge.enRoute');
}

// departure: la salida de hoy de la que es el estado (en el horario de una línea, ninguna).
export function statusLabel(status, delayMinutes, departure = null) {
    if (status === 'live') {
        // Retraso no creíble (null): se muestra la llegada estimada sin afirmar ni «en hora» ni retraso.
        if (delayMinutes === null || delayMinutes === undefined) return { text: '', className: 'status-muted' };
        if (delayMinutes > 0) return { text: I18n.t('app.status.delay', { min: delayMinutes }), className: 'status-warn' };
        if (delayMinutes <= -2) return { text: I18n.t('app.status.early', { min: -delayMinutes }), className: 'status-warn' };
        return { text: I18n.t('app.status.onTime'), className: 'status-ok' };
    }
    if (status === 'finished') return { text: I18n.t('app.status.finished'), className: 'status-muted' };
    if (status === 'departed') return { text: I18n.t('app.status.departed'), className: 'status-muted' };
    // Sin bus todavía, pero el de su vuelta anterior viene tarde: sale tarde (el motor lo dice con reason).
    if (isPreviousTripLate(status, delayMinutes, departure)) return { text: I18n.t('app.status.delay', { min: delayMinutes }), className: 'status-warn' };
    if (status === 'estimated') return { text: I18n.t('app.status.estimated'), className: 'status-muted' };
    if (status === 'likely_departed') return { text: noData('app.status.likelyDeparted', 'app.status.likelyDepartedTrain'), className: 'status-muted' };
    if (status === 'cancelled') return { text: I18n.t('app.status.cancelled'), className: 'status-warn' };
    if (mayNotRun(departure)) return { text: I18n.t('app.status.mayNotRun'), className: 'status-warn' };
    return { text: I18n.t('app.status.scheduled'), className: 'status-muted' };
}

// Un viaje sin bus que saldrá tarde porque su bus viene con retraso de la vuelta anterior.
export function isPreviousTripLate(status, delayMinutes, departure) {
    return status === 'estimated' && departure?.reason === 'previous_trip' && delayMinutes > 0;
}

function delayText(status, delayMinutes) {
    if (status !== 'live') return '';
    if (delayMinutes > 0) return I18n.t('app.delay.late', { min: delayMinutes });
    if (delayMinutes <= -2) return I18n.t('app.delay.early', { min: -delayMinutes });
    return '';
}

// Bajo los minutos: el retraso si va en directo, por qué sale tarde si su bus viene tarde de la vuelta anterior, o en
// gris la hora oficial si la hora es estimada.
export function setDelaySub(node, status, delayMinutes, scheduledTime = null, departure = null) {
    let text = delayText(status, delayMinutes);
    node.classList.remove('is-official');
    if (isPreviousTripLate(status, delayMinutes, departure)) {
        text = `${I18n.t('app.status.delay', { min: delayMinutes })} · ${I18n.t('app.previousTripLate')}`;
    } else if (text === '' && status === 'estimated' && scheduledTime) {
        text = I18n.t('app.officialTime', { time: scheduledTime });
        node.classList.add('is-official');
    }
    node.textContent = text;
    node.hidden = text === '';
}

export function setPillContent(button, iconSvg, label, area, hint) {
    button.innerHTML = '';
    const icon = document.createElement('span');
    icon.innerHTML = iconSvg;
    const textWrap = document.createElement('span');
    const labelEl = document.createElement('span');
    labelEl.textContent = label;
    textWrap.appendChild(labelEl);
    if (area) {
        const small = document.createElement('small');
        small.textContent = area;
        textWrap.appendChild(small);
    }
    if (hint) {
        const hintEl = document.createElement('small');
        hintEl.className = 'pill-hint';
        hintEl.textContent = hint;
        textWrap.appendChild(hintEl);
    }
    button.append(icon, textWrap);
}
