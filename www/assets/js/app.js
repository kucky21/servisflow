/**
 * ServisHub – klientská logika (vanilla JS, bez build kroku).
 *
 *  - AJAX formuláře a signály přes fetch API s podporou Nette snippetů a payloadu
 *  - toast notifikace, potvrzovací dialog pro destruktivní akce
 *  - živé filtrování seznamů, závislý výběr zařízení, modal změny stavu
 */
(() => {
    'use strict';

    const AJAX_HEADERS = { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' };

    // ------------------------------------------------------------------
    // Ikony, tooltipy, inicializace nového obsahu
    // ------------------------------------------------------------------
    function refreshIcons() {
        if (window.lucide) {
            window.lucide.createIcons({ attrs: { 'aria-hidden': 'true' } });
        }
    }

    function initTooltips(root) {
        if (!window.bootstrap) return;
        root.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => {
            window.bootstrap.Tooltip.getOrCreateInstance(el);
        });
    }

    function initContent(root = document) {
        refreshIcons();
        initTooltips(root);
        if (window.Nette && root !== document) {
            root.querySelectorAll('form').forEach((form) => window.Nette.initForm(form));
        }
    }

    // ------------------------------------------------------------------
    // Toasty
    // ------------------------------------------------------------------
    const TOAST_ICONS = { success: 'circle-check', danger: 'circle-alert', warning: 'triangle-alert', info: 'info' };

    function toast(message, type = 'success') {
        const stack = document.getElementById('toast-stack');
        if (!stack) return;
        const normalized = TOAST_ICONS[type] ? type : 'info';
        const el = document.createElement('div');
        el.className = `sh-toast sh-toast-${normalized}`;
        el.setAttribute('role', normalized === 'danger' ? 'alert' : 'status');
        el.innerHTML = `<i data-lucide="${TOAST_ICONS[normalized]}" class="sh-toast-icon"></i><div class="sh-toast-text"></div>
            <button type="button" class="sh-toast-close" aria-label="Zavřít">&times;</button>`;
        el.querySelector('.sh-toast-text').textContent = message;
        stack.appendChild(el);
        refreshIcons();

        const close = () => {
            el.classList.add('is-leaving');
            setTimeout(() => el.remove(), 200);
        };
        el.querySelector('.sh-toast-close').addEventListener('click', close);
        setTimeout(close, normalized === 'danger' ? 8000 : 4500);
    }
    window.ServisHub = { toast };

    function showFlashes() {
        const data = document.getElementById('flash-data');
        if (!data) return;
        try {
            JSON.parse(data.textContent || '[]').forEach((f) => toast(f.message, f.type));
        } catch (e) { /* bez flash zpráv */ }
    }

    // ------------------------------------------------------------------
    // Zpracování odpovědi Nette (payload se snippety)
    // ------------------------------------------------------------------
    function applyPayload(payload, sourceForm = null) {
        if (!payload || typeof payload !== 'object') return;

        if (payload.redirect) {
            window.location.href = payload.redirect;
            return;
        }

        if (payload.success && sourceForm) {
            const modalEl = sourceForm.closest('.modal');
            if (modalEl && window.bootstrap) {
                window.bootstrap.Modal.getOrCreateInstance(modalEl).hide();
            }
        }

        if (payload.snippets) {
            Object.entries(payload.snippets).forEach(([id, html]) => {
                const el = document.getElementById(id);
                if (!el) return;
                el.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((t) => window.bootstrap?.Tooltip.getInstance(t)?.dispose());
                el.innerHTML = html;
                initContent(el);
            });
        }

        (payload.toasts || []).forEach((t) => toast(t.message, t.type));
    }

    async function ajaxRequest(url, options = {}) {
        const response = await fetch(url, {
            credentials: 'same-origin',
            ...options,
            headers: { ...AJAX_HEADERS, ...(options.headers || {}) },
        });
        const type = response.headers.get('Content-Type') || '';
        if (!type.includes('application/json')) {
            if (response.redirected) {
                window.location.href = response.url;
                return null;
            }
            throw new Error(`Neočekávaná odpověď serveru (${response.status}).`);
        }
        return response.json();
    }

    function setLoading(button, loading) {
        if (!button) return;
        button.classList.toggle('is-loading', loading);
        button.disabled = loading;
    }

    // ------------------------------------------------------------------
    // AJAX formuláře (třída .ajax)
    // ------------------------------------------------------------------
    document.addEventListener('submit', async (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.classList.contains('ajax')) return;
        event.preventDefault();

        const submitter = event.submitter || form.querySelector('[type="submit"]');
        const data = new FormData(form);
        if (submitter && submitter.name) data.append(submitter.name, submitter.value || '');

        setLoading(submitter, true);
        try {
            const payload = await ajaxRequest(form.action, { method: 'POST', body: data });
            applyPayload(payload, form);
        } catch (error) {
            toast(error.message || 'Požadavek se nezdařil.', 'danger');
        } finally {
            setLoading(submitter, false);
        }
    });

    // ------------------------------------------------------------------
    // Potvrzení destruktivních akcí ([data-confirm])
    // ------------------------------------------------------------------
    const confirmModalEl = document.getElementById('confirm-modal');
    let pendingConfirm = null;

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-confirm]');
        if (!trigger) return;
        event.preventDefault();

        if (!confirmModalEl || !window.bootstrap) {
            if (window.confirm(trigger.dataset.confirm)) runConfirmed(trigger);
            return;
        }
        confirmModalEl.querySelector('[data-confirm-title]').textContent = trigger.dataset.confirm;
        confirmModalEl.querySelector('[data-confirm-text]').textContent = trigger.dataset.confirmText || 'Akci nelze vrátit zpět.';
        pendingConfirm = trigger;
        window.bootstrap.Modal.getOrCreateInstance(confirmModalEl).show();
    });

    confirmModalEl?.querySelector('[data-confirm-accept]')?.addEventListener('click', () => {
        window.bootstrap.Modal.getOrCreateInstance(confirmModalEl).hide();
        if (pendingConfirm) runConfirmed(pendingConfirm);
        pendingConfirm = null;
    });

    async function runConfirmed(trigger) {
        const url = trigger.dataset.action;
        if (!url) return;

        if (trigger.hasAttribute('data-ajax')) {
            setLoading(trigger, true);
            try {
                applyPayload(await ajaxRequest(url, { method: 'POST' }));
            } catch (error) {
                toast(error.message, 'danger');
            } finally {
                setLoading(trigger, false);
            }
            return;
        }

        // Klasický POST (s přesměrováním) – signály v Nette chrání same-origin kontrola
        const form = document.createElement('form');
        form.method = 'post';
        form.action = url;
        document.body.appendChild(form);
        form.submit();
    }

    // ------------------------------------------------------------------
    // Živé filtrování seznamů ([data-live-filter="snippet"])
    // ------------------------------------------------------------------
    document.querySelectorAll('form[data-live-filter]').forEach((form) => {
        let timer = null;
        let controller = null;

        const run = async () => {
            const params = new URLSearchParams(new FormData(form));
            [...params.keys()].forEach((key) => { if (params.get(key) === '') params.delete(key); });
            const url = `${form.action.split('?')[0]}${params.toString() ? `?${params}` : ''}`;

            controller?.abort();
            controller = new AbortController();
            form.classList.add('is-loading');
            try {
                const payload = await ajaxRequest(url, { signal: controller.signal });
                applyPayload(payload);
                window.history.replaceState(null, '', url);
            } catch (error) {
                if (error.name !== 'AbortError') toast('Filtrování se nezdařilo.', 'danger');
            } finally {
                form.classList.remove('is-loading');
            }
        };

        form.addEventListener('input', (e) => {
            if (e.target.matches('input[type="search"], input[type="text"]')) {
                clearTimeout(timer);
                timer = setTimeout(run, 300);
            }
        });
        form.addEventListener('change', (e) => {
            if (e.target.matches('select, input[type="checkbox"]')) run();
        });
        form.addEventListener('submit', (e) => { e.preventDefault(); run(); });
    });

    // ------------------------------------------------------------------
    // Navigace: klikatelné řádky, sidebar, klávesová zkratka
    // ------------------------------------------------------------------
    document.addEventListener('click', (event) => {
        const row = event.target.closest('tr.row-link[data-href]');
        if (!row || event.target.closest('a, button, input, select, label, form')) return;
        if (event.ctrlKey || event.metaKey) {
            window.open(row.dataset.href, '_blank');
        } else {
            window.location.href = row.dataset.href;
        }
    });

    const sidebar = document.getElementById('sidebar');
    const backdrop = document.querySelector('[data-sidebar-close]');
    const toggleSidebar = (open) => {
        sidebar?.classList.toggle('is-open', open);
        backdrop?.classList.toggle('is-visible', open);
    };
    document.querySelector('[data-sidebar-toggle]')?.addEventListener('click', () => toggleSidebar(!sidebar.classList.contains('is-open')));
    backdrop?.addEventListener('click', () => toggleSidebar(false));

    document.addEventListener('keydown', (event) => {
        if (event.key !== '/' || event.target.closest('input, textarea, select, [contenteditable]')) return;
        const search = document.querySelector('.topbar-search input');
        if (search) {
            event.preventDefault();
            search.focus();
        }
    });

    // ------------------------------------------------------------------
    // Formulář zakázky: zařízení podle vybraného zákazníka
    // ------------------------------------------------------------------
    const orderForm = document.querySelector('form[data-order-form]');
    if (orderForm) {
        const customerSelect = orderForm.querySelector('[data-device-source="customer"]');
        const deviceSelect = orderForm.querySelector('select[name="device_id"]');
        const newDeviceLink = orderForm.querySelector('[data-new-device-link]');

        const updateNewDeviceLink = () => {
            if (!newDeviceLink) return;
            const base = newDeviceLink.dataset.newDeviceLink;
            newDeviceLink.href = customerSelect?.value ? `${base}?customerId=${encodeURIComponent(customerSelect.value)}` : base;
        };

        const loadDevices = async (preserve) => {
            if (!customerSelect || !deviceSelect) return;
            const previous = preserve ? deviceSelect.value : '';
            deviceSelect.innerHTML = '';
            const prompt = new Option(customerSelect.value ? 'Načítám zařízení…' : '— nejprve vyberte zákazníka —', '');
            deviceSelect.add(prompt);
            updateNewDeviceLink();
            if (!customerSelect.value) return;

            try {
                const url = new URL(orderForm.dataset.devicesUrl, window.location.href);
                url.searchParams.set('customerId', customerSelect.value);
                const data = await ajaxRequest(url.toString());
                const devices = data?.devices || [];
                prompt.textContent = devices.length ? '— vyberte zařízení —' : 'Zákazník nemá žádné zařízení – přidejte ho';
                devices.forEach((d) => deviceSelect.add(new Option(d.label, d.value, false, String(d.value) === previous)));
                if (devices.length === 1) deviceSelect.value = String(devices[0].value);
            } catch (e) {
                prompt.textContent = 'Zařízení se nepodařilo načíst';
            }
        };

        customerSelect?.addEventListener('change', () => loadDevices(false));
        if (customerSelect?.value && deviceSelect && deviceSelect.options.length <= 1) {
            loadDevices(true);
        }
        updateNewDeviceLink();
    }

    // ------------------------------------------------------------------
    // Modal změny stavu zakázky
    // ------------------------------------------------------------------
    document.getElementById('status-modal')?.addEventListener('show.bs.modal', (event) => {
        const button = event.relatedTarget;
        const modal = event.currentTarget;
        if (!button) return;
        const select = modal.querySelector('select[name="status"]');
        const note = modal.querySelector('textarea[name="note"]');
        const requiresNote = button.dataset.requiresNote === '1';

        if (select) select.value = button.dataset.status;
        modal.querySelector('[data-status-target]').textContent = button.dataset.statusLabel;
        modal.querySelector('[data-note-label]').textContent = requiresNote ? 'Důvod (povinné)' : 'Poznámka (nepovinné)';
        modal.querySelector('[data-note-help]').textContent = button.dataset.notePrompt || 'Poznámka se uloží do historie zakázky.';
        if (note) {
            note.required = requiresNote;
            note.placeholder = button.dataset.notePrompt || '';
            note.classList.remove('is-invalid');
        }
    });
    document.getElementById('status-modal')?.addEventListener('shown.bs.modal', (event) => {
        event.currentTarget.querySelector('textarea[name="note"]')?.focus();
    });
    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!form.matches?.('[data-modal-form]')) return;
        const note = form.querySelector('textarea[name="note"]');
        if (note?.required && !note.value.trim()) {
            event.preventDefault();
            event.stopImmediatePropagation();
            note.classList.add('is-invalid');
            note.focus();
            toast('U tohoto přechodu je nutné uvést důvod.', 'warning');
        }
    }, true);

    // ------------------------------------------------------------------
    // Klientská validace Nette Forms – chyby u polí místo alertu
    // ------------------------------------------------------------------
    if (window.Nette) {
        window.Nette.showFormErrors = (form, errors) => {
            form.querySelectorAll('.is-invalid').forEach((el) => el.classList.remove('is-invalid'));
            form.querySelectorAll('.invalid-feedback[data-client]').forEach((el) => el.remove());

            let focus = null;
            errors.forEach(({ element, message }) => {
                if (!element || element.classList.contains('is-invalid')) return;
                element.classList.add('is-invalid');
                const feedback = document.createElement('div');
                feedback.className = 'invalid-feedback';
                feedback.dataset.client = '1';
                feedback.textContent = message;
                const host = element.closest('.input-group, .form-check, .segmented') || element;
                host.insertAdjacentElement('afterend', feedback);
                focus ??= element;
            });
            focus?.focus();
        };
    }

    // ------------------------------------------------------------------
    // Přihlášení: rychlý výběr demo účtu (vyplní pouze e-mail)
    // ------------------------------------------------------------------
    document.querySelectorAll('[data-demo-login]').forEach((button) => {
        button.addEventListener('click', () => {
            const email = document.querySelector('input[name="email"]');
            const password = document.querySelector('input[name="password"]');
            if (email) email.value = button.dataset.demoLogin;
            password?.focus();
        });
    });

    // ------------------------------------------------------------------
    const boot = () => {
        initContent(document);
        showFlashes();
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
    } else {
        boot();
    }
})();
