// Info: qué redes se guardan para usarlas sin conexión (js/offline.js) y el formulario de sugerencias y errores.
(() => {
    const root = document.documentElement;
    if (!root.classList.contains('bide')) return;

    const MAX_FEEDBACK = 1500;

    function translate(key, vars) {
        if (window.I18n) return I18n.t(key, vars);
        return key;
    }

    function createNode(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text != null) node.textContent = text;
        return node;
    }

    function formatSize(bytes) {
        if (!Number.isFinite(bytes)) return '';
        if (bytes < 1024 * 1024) return Math.max(1, Math.round(bytes / 1024)) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1).replace('.', ',') + ' MB';
    }

    function formatDate(iso) {
        if (!iso) return '';
        const [year, month, day] = iso.split('-');
        return `${day}/${month}/${year}`;
    }

    // ===== Horarios sin conexión =====

    const offlineList = document.querySelector('[data-bide-offline]');
    let offlineSizes = {};

    function offlineDetail(slug, saved) {
        if (saved) return [translate('bide.offline.saved', { date: formatDate(saved.generated) }), formatSize(saved.bytes)].filter(Boolean).join(' · ');
        return formatSize(offlineSizes[slug]);
    }

    function renderOffline() {
        if (!offlineList) return;
        offlineList.replaceChildren();
        if (typeof BideOffline === 'undefined' || !BideOffline.supported()) {
            offlineList.appendChild(createNode('li', 'bide-offline-unsupported', translate('bide.offline.unsupported')));
            return;
        }
        const saved = BideOffline.saved();
        offlineList.appendChild(saveAllItem(saved));
        for (const slug of BideNetworks.slugs) {
            const name = BideNetworks.name(slug);
            const item = createNode('li', 'bide-offline-item');
            const label = createNode('label');
            const text = createNode('span', 'bide-offline-text');
            text.append(createNode('span', 'bide-swatch bide-swatch--' + slug), createNode('strong', null, name));
            const detail = createNode('small', 'bide-offline-detail', offlineDetail(slug, saved[slug]));
            text.appendChild(detail);
            const toggle = createNode('input', 'bide-toggle');
            toggle.type = 'checkbox';
            toggle.checked = Boolean(saved[slug]);
            toggle.setAttribute('aria-label', translate('bide.offline.toggle', { app: name }));
            toggle.addEventListener('change', () => changeOffline(slug, toggle, detail));
            label.append(text, toggle);
            item.appendChild(label);
            offlineList.appendChild(item);
        }
    }

    // «Guardar todas las redes»: todas las paradas y líneas, con su horario oficial y, donde hay, la hora estimada.
    function saveAllItem(saved) {
        const item = createNode('li', 'bide-offline-all');
        const button = createNode('button', 'bide-offline-all-button');
        button.type = 'button';
        const missing = BideNetworks.slugs.filter((slug) => !saved[slug]);
        if (missing.length === 0) {
            button.textContent = translate('bide.offline.allSaved');
            button.disabled = true;
        } else {
            const bytes = missing.reduce((sum, slug) => sum + (offlineSizes[slug] || 0), 0);
            button.textContent = [translate('bide.offline.saveAll'), formatSize(bytes)].filter(Boolean).join(' · ');
        }
        button.addEventListener('click', () => saveAll(button, missing));
        item.appendChild(button);
        return item;
    }

    async function saveAll(button, slugs) {
        button.disabled = true;
        let failed = false;
        for (const [index, slug] of slugs.entries()) {
            button.textContent = translate('bide.offline.savingAll', { done: index, total: slugs.length });
            await BideOffline.save(slug).catch(() => { failed = true; });
        }
        renderOffline();
        if (failed) {
            const status = offlineList.querySelector('.bide-offline-all-button');
            if (status) status.textContent = translate('bide.offline.error');
        }
    }

    async function changeOffline(slug, toggle, detail) {
        toggle.disabled = true;
        try {
            if (toggle.checked) {
                detail.textContent = translate('bide.offline.saving');
                const saved = await BideOffline.save(slug);
                detail.textContent = offlineDetail(slug, saved);
            } else {
                await BideOffline.remove(slug);
                detail.textContent = offlineDetail(slug, null);
            }
        } catch (error) {
            toggle.checked = false;
            await BideOffline.remove(slug).catch(() => {});
            detail.textContent = translate('bide.offline.error');
        } finally {
            toggle.disabled = false;
        }
    }

    async function loadOfflineSizes() {
        try {
            const response = await fetch('/offline-data/index.json', { cache: 'no-store' });
            if (!response.ok) return;
            const index = await response.json();
            for (const network of index.networks || []) offlineSizes[network.slug] = network.bytes;
            renderOffline();
        } catch (error) {
            // Sin conexión se ven las redes sin su tamaño.
        }
    }

    // ===== Sugerencias y errores =====

    const feedback = document.querySelector('.bide-feedback');

    function feedbackParts() {
        const type = feedback.querySelector('[data-feedback-type][aria-pressed="true"]');
        const text = feedback.querySelector('[data-feedback-text]').value.trim();
        const subject = translate('bide.feedback.subject', { type: type.textContent.trim() });
        let body = text;
        if (feedback.querySelector('[data-feedback-tech]').checked) body += '\n\n---\n' + technicalData();
        return { email: feedback.dataset.feedbackEmail, subject, text, body };
    }

    // Lo justo para reproducir un fallo: versión, página, sistema y pantalla. Nada de ubicación ni favoritos.
    function technicalData() {
        let app = 'Bide+';
        if (window.__bbNetwork) app = BideNetworks.name(window.__bbNetwork);
        let installed = 'no';
        if (root.classList.contains('is-standalone')) installed = 'sí';
        return [
            'Versión: ' + (feedback.dataset.feedbackVersion || ''),
            'App: ' + app + ' · ' + location.pathname,
            'Sistema: ' + navigator.userAgent,
            `Pantalla: ${window.innerWidth}×${window.innerHeight} (×${window.devicePixelRatio || 1}) · instalada: ${installed} · idioma: ${root.lang}`,
        ].join('\n');
    }

    function setFeedbackStatus(key, vars) {
        feedback.querySelector('[data-feedback-status]').textContent = translate(key, vars);
    }

    function setUpFeedback() {
        if (!feedback) return;
        const textarea = feedback.querySelector('[data-feedback-text]');
        const count = feedback.querySelector('[data-feedback-count]');
        textarea.addEventListener('input', () => {
            count.textContent = `${textarea.value.length} / ${MAX_FEEDBACK}`;
            feedback.querySelector('[data-feedback-status]').textContent = '';
        });
        feedback.querySelectorAll('[data-feedback-type]').forEach((button) => {
            button.addEventListener('click', () => {
                feedback.querySelectorAll('[data-feedback-type]').forEach((other) => other.setAttribute('aria-pressed', String(other === button)));
            });
        });
        feedback.querySelector('[data-feedback-send]').addEventListener('click', () => {
            const parts = feedbackParts();
            if (!parts.text) {
                setFeedbackStatus('bide.feedback.empty');
                textarea.focus();
                return;
            }
            location.href = `mailto:${parts.email}?subject=${encodeURIComponent(parts.subject)}&body=${encodeURIComponent(parts.body)}`;
        });
        feedback.querySelector('[data-feedback-copy]').addEventListener('click', async () => {
            const parts = feedbackParts();
            if (!parts.text) {
                setFeedbackStatus('bide.feedback.empty');
                textarea.focus();
                return;
            }
            try {
                await navigator.clipboard.writeText(`${parts.subject}\n\n${parts.body}`);
                setFeedbackStatus('bide.feedback.copied', { email: parts.email });
            } catch (error) {
                textarea.select();
            }
        });
    }

    renderOffline();
    loadOfflineSizes();
    setUpFeedback();
    if (typeof BideOffline !== 'undefined') BideOffline.refresh().catch(() => {});
    // Al cambiar de idioma se rehace la lista con los textos nuevos.
    window.addEventListener('bide:lang', renderOffline);
})();
