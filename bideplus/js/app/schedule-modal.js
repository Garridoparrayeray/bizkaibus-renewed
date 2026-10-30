// Horario en texto de una línea (ventana con todas las salidas del día).
import { FEATURES, el, state } from './core.js';

function extractScheduleFields(block) {
    const fields = { season: '', from: '', to: '', outbound: '', returnTrip: '' };
    for (const [key, value] of Object.entries(block)) {
        if (!value) continue;
        const k = key.toUpperCase();
        if (k.includes('EU') && !k.includes('CAS')) continue;
        if (k.includes('TEMPORADA')) fields.season = value;
        else if (k.includes('DESDE')) fields.from = value;
        else if (k.includes('HASTA')) fields.to = value;
        else if (k.includes('CAS') && (k.includes('JOAN') || k.includes('IDA'))) fields.outbound = value;
        else if (k.includes('CAS') && (k.includes('ETORRI') || k.includes('VUELTA'))) fields.returnTrip = value;
    }
    return fields;
}

async function openScheduleModal() {
    if (!state.currentLine) return;

    el.scheduleTextToggle.disabled = true;
    el.scheduleTextToggle.textContent = I18n.t('app.loading');

    let data;
    try {
        data = await Api.lineScheduleText(state.currentLine.id);
    } catch (e) {
        el.scheduleTextToggle.disabled = false;
        el.scheduleTextToggle.textContent = I18n.t('app.officialSchedule2026');
        return;
    }
    el.scheduleTextToggle.disabled = false;
    el.scheduleTextToggle.textContent = I18n.t('app.officialSchedule2026');

    el.scheduleModalLine.textContent = `${state.currentLine.code} · ${state.currentLine.name}`;
    el.scheduleModalContent.innerHTML = '';

    if (data.schedule.length === 0) {
        const p = document.createElement('p');
        p.textContent = I18n.t('app.noOfficialScheduleText');
        el.scheduleModalContent.appendChild(p);
    } else {
        const parseDate = (text) => {
            const m = /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/.exec(text || '');
            if (!m) return null;
            return new Date(Number(m[3]), Number(m[2]) - 1, Number(m[1]));
        };
        const today = new Date();
        today.setHours(0, 0, 0, 0);

        const entries = data.schedule.map((rawBlock) => {
            const fields = extractScheduleFields(rawBlock);
            const from = parseDate(fields.from);
            const to = parseDate(fields.to);
            const isCurrent = from !== null && to !== null && from <= today && today <= to;
            return { fields, isCurrent, isPast: to !== null && to < today };
        });
        entries.sort((x, y) => Number(y.isCurrent) - Number(x.isCurrent) || Number(x.isPast) - Number(y.isPast));

        for (const { fields, isCurrent, isPast } of entries) {
            const block = document.createElement('div');
            block.className = 'schedule-block';
            if (isCurrent) block.classList.add('is-current');
            if (isPast) block.classList.add('is-past');

            const h4 = document.createElement('h4');
            h4.textContent = fields.season || I18n.t('app.schedule');
            block.appendChild(h4);
            if (isCurrent) {
                const tag = document.createElement('span');
                tag.className = 'schedule-current-tag';
                tag.textContent = I18n.t('app.currentToday');
                h4.appendChild(tag);
            }

            if (fields.from || fields.to) {
                const dates = document.createElement('p');
                dates.className = 'schedule-dates';
                dates.textContent = [fields.from, fields.to].filter(Boolean).join(' – ');
                block.appendChild(dates);
            }

            if (fields.outbound) {
                const h5 = document.createElement('h5');
                h5.textContent = I18n.t('app.outbound');
                const p = document.createElement('p');
                p.textContent = fields.outbound;
                block.append(h5, p);
            }

            if (fields.returnTrip) {
                const h5 = document.createElement('h5');
                h5.textContent = I18n.t('app.returnTrip');
                const p = document.createElement('p');
                p.textContent = fields.returnTrip;
                block.append(h5, p);
            }

            el.scheduleModalContent.appendChild(block);
        }
    }

    el.scheduleModal.showModal();
}

export function setUpScheduleModal() {
    el.scheduleTextToggle.addEventListener('click', openScheduleModal);

    el.scheduleModalClose.addEventListener('click', () => el.scheduleModal.close());
    el.scheduleModal.addEventListener('click', (e) => {
        if (e.target === el.scheduleModal) el.scheduleModal.close();
    });

    if (!FEATURES.schedule_text) {
        el.scheduleTextToggle.hidden = true;
    }
}
