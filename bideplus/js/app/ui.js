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

export function liveBadgeText(status, etaMinutes) {
    if (status === 'departed') return I18n.t('app.badge.leaving');
    if (status === 'estimated') return I18n.t('app.badge.estimated');
    if (status === 'likely_departed') return I18n.t('app.badge.likelyDeparted');
    if (status !== 'live') return I18n.t('app.badge.scheduled');
    if (etaMinutes <= 0) return I18n.t('app.badge.atStop');
    if (etaMinutes <= 3) return I18n.t('app.badge.arriving');
    return I18n.t('app.badge.enRoute');
}

export function statusLabel(status, delayMinutes) {
    if (status === 'live') {
        // Retraso no creíble (null): se muestra la llegada estimada sin afirmar ni «en hora» ni retraso.
        if (delayMinutes === null || delayMinutes === undefined) return { text: '', className: 'status-muted' };
        if (delayMinutes > 0) return { text: I18n.t('app.status.delay', { min: delayMinutes }), className: 'status-warn' };
        if (delayMinutes <= -2) return { text: I18n.t('app.status.early', { min: -delayMinutes }), className: 'status-warn' };
        return { text: I18n.t('app.status.onTime'), className: 'status-ok' };
    }
    if (status === 'finished') return { text: I18n.t('app.status.finished'), className: 'status-muted' };
    if (status === 'departed') return { text: I18n.t('app.status.departed'), className: 'status-muted' };
    if (status === 'estimated') return { text: I18n.t('app.status.estimated'), className: 'status-muted' };
    if (status === 'likely_departed') return { text: I18n.t('app.status.likelyDeparted'), className: 'status-muted' };
    return { text: I18n.t('app.status.scheduled'), className: 'status-muted' };
}

function delayText(status, delayMinutes) {
    if (status !== 'live') return '';
    if (delayMinutes > 0) return I18n.t('app.delay.late', { min: delayMinutes });
    if (delayMinutes <= -2) return I18n.t('app.delay.early', { min: -delayMinutes });
    return '';
}

export function setDelaySub(node, status, delayMinutes) {
    const text = delayText(status, delayMinutes);
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
