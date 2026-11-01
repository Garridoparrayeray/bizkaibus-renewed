// «Ver horario oficial»: el horario en texto del operador, por periodos.
import { FEATURES, el, state } from './core.js';
import { schedulePeriods } from './schedule-periods.js';

function textBlock(title, text) {
    const heading = document.createElement('h5');
    heading.textContent = title;
    const paragraph = document.createElement('p');
    paragraph.textContent = text;
    return [heading, paragraph];
}

function periodBlock(period) {
    const block = document.createElement('div');
    block.className = 'schedule-block';
    const heading = document.createElement('h4');
    heading.textContent = period.season || I18n.t('app.schedule');
    block.appendChild(heading);
    if (period.isCurrent) {
        block.classList.add('is-current');
        const tag = document.createElement('span');
        tag.className = 'schedule-current-tag';
        tag.textContent = I18n.t('app.currentToday');
        heading.appendChild(tag);
    }
    if (period.dates.length > 0) {
        const dates = document.createElement('p');
        dates.className = 'schedule-dates';
        dates.textContent = period.dates.join(' · ');
        block.appendChild(dates);
    }
    if (period.outbound) block.append(...textBlock(I18n.t('app.outbound'), period.outbound));
    if (period.returnTrip) block.append(...textBlock(I18n.t('app.returnTrip'), period.returnTrip));
    return block;
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
        el.scheduleTextToggle.textContent = I18n.t('app.officialSchedule');
        return;
    }
    el.scheduleTextToggle.disabled = false;
    el.scheduleTextToggle.textContent = I18n.t('app.officialSchedule');

    el.scheduleModalLine.textContent = `${state.currentLine.code} · ${state.currentLine.name}`;
    el.scheduleModalContent.innerHTML = '';

    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const periods = schedulePeriods(data.schedule, today);
    if (periods.length === 0) {
        const empty = document.createElement('p');
        empty.textContent = I18n.t('app.noOfficialScheduleText');
        el.scheduleModalContent.appendChild(empty);
    }
    for (const period of periods) {
        el.scheduleModalContent.appendChild(periodBlock(period));
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
