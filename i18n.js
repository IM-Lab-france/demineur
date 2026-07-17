(() => {
    'use strict';

    const supported = ['fr', 'en', 'de', 'nl'];
    const storageKey = 'minesweeper-language';
    const catalogues = {};
    let language = 'fr';
    let messages = {};

    function normalize(value) {
        const candidate = String(value || '').toLowerCase().split('-')[0];
        return supported.includes(candidate) ? candidate : 'fr';
    }

    function interpolate(template, params = {}) {
        return String(template).replace(/\{(\w+)\}/g, (match, name) =>
            Object.prototype.hasOwnProperty.call(params, name) ? String(params[name]) : match
        );
    }

    function t(key, params = {}, fallback = '') {
        return interpolate(messages[key] ?? fallback ?? key, params);
    }

    function apply(root = document) {
        root.querySelectorAll('[data-i18n]').forEach(element => {
            element.textContent = t(element.dataset.i18n, {}, element.textContent);
        });
        root.querySelectorAll('[data-i18n-html]').forEach(element => {
            element.innerHTML = t(element.dataset.i18nHtml, {}, element.innerHTML);
        });
        ['placeholder', 'title', 'aria-label'].forEach(attribute => {
            const dataName = `i18n${attribute.split('-').map(part => part[0].toUpperCase() + part.slice(1)).join('')}`;
            root.querySelectorAll(`[data-i18n-${attribute}]`).forEach(element => {
                element.setAttribute(attribute, t(element.dataset[dataName], {}, element.getAttribute(attribute) || ''));
            });
        });
        document.documentElement.lang = language;
        document.querySelectorAll('[data-language-select]').forEach(select => { select.value = language; });
        document.querySelectorAll('[data-language-choice]').forEach(button => {
            button.classList.toggle('active', button.dataset.languageChoice === language);
            button.setAttribute('aria-current', button.dataset.languageChoice === language ? 'true' : 'false');
        });
    }

    async function loadCatalogue(value) {
        if (catalogues[value]) return catalogues[value];
        const response = await fetch(`/locales/index.php?lang=${encodeURIComponent(value)}`, { cache: 'no-cache' });
        if (!response.ok) throw new Error(`Catalogue ${value} indisponible`);
        catalogues[value] = await response.json();
        return catalogues[value];
    }

    async function setLanguage(value, persist = true) {
        const next = normalize(value);
        messages = await loadCatalogue(next);
        language = next;
        if (persist) localStorage.setItem(storageKey, next);
        document.cookie = `minesweeper_language=${next}; Max-Age=31536000; Path=/; SameSite=Lax`;
        apply();
        window.dispatchEvent(new CustomEvent('languagechange', { detail: { language } }));
        const loader = document.getElementById('languageChangeLoader');
        if (loader) {
            loader.querySelector('strong').textContent = t('language.changing', {}, 'Loading…');
            requestAnimationFrame(() => requestAnimationFrame(() => {
                loader.classList.remove('show');
                document.body.classList.remove('i18n-loading');
            }));
        }
        return language;
    }

    function rememberLanguage(value) {
        const next = normalize(value);
        localStorage.setItem(storageKey, next);
        document.cookie = `minesweeper_language=${next}; Max-Age=31536000; Path=/; SameSite=Lax`;
        return next;
    }

    const preferred = normalize(localStorage.getItem(storageKey) || navigator.languages?.[0] || navigator.language);
    const ready = setLanguage(preferred, false).catch(() => setLanguage('fr', false));

    function showLanguageLoader(next) {
        let loader = document.getElementById('languageChangeLoader');
        if (!loader) {
            loader = document.createElement('div');
            loader.id = 'languageChangeLoader';
            loader.className = 'language-change-loader';
            loader.setAttribute('role', 'status');
            loader.setAttribute('aria-live', 'assertive');
            loader.innerHTML = '<div class="language-loader-card"><span class="language-loader-spinner" aria-hidden="true"></span><strong></strong></div>';
            document.body.appendChild(loader);
        }
        loader.querySelector('strong').textContent = t('language.changing', {}, 'Changement de langue…');
        loader.dataset.language = next;
        loader.classList.add('show');
    }

    async function changeAndReload(nextValue) {
        const next = normalize(nextValue);
        if (next === language) return;
        showLanguageLoader(next);
        document.querySelectorAll('[data-language-choice],[data-language-select]').forEach(control => { control.disabled = true; });
        rememberLanguage(next);
        try {
            await window.persistLanguagePreference?.(next);
        } catch (error) {
            console.error(error);
        }
        window.location.reload();
    }

    document.addEventListener('change', event => {
        if (event.target.matches('[data-language-select]')) changeAndReload(event.target.value);
    });
    document.addEventListener('click', event => {
        const choice = event.target.closest('[data-language-choice]');
        if (choice) changeAndReload(choice.dataset.languageChoice);
    });

    window.i18n = { t, apply, setLanguage, rememberLanguage, get language() { return language; }, supported, ready };
    window.t = t;
})();
