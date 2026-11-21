(function () {
    if (window.fitodomikTheme) return;
    const html = document.documentElement;
    const storageKeys = ['fitodomik-theme', 'theme'];
    const validTheme = (theme) => theme === 'dark' || theme === 'light';
    function readCookieTheme() {
        const match = document.cookie.match(/(?:^|;\s*)theme=(dark|light)(?:;|$)/);
        return match ? match[1] : '';
    }
    function readStoredTheme() {
        try {
            for (const key of storageKeys) {
                const value = localStorage.getItem(key);
                if (validTheme(value)) {
                    return value;
                }
            }
        } catch {
            return '';
        }
        return '';
    }
    function writeStoredTheme(theme) {
        try {
            for (const key of storageKeys) {
                localStorage.setItem(key, theme);
            }
        } catch {
        }
    }
    function writeCookieTheme(theme) {
        document.cookie = `theme=${theme}; path=/; max-age=31536000; samesite=lax`;
    }
    function initialTheme() {
        return readStoredTheme()
            || readCookieTheme()
            || (html.getAttribute('data-theme') === 'dark' ? 'dark' : 'light');
    }
    function updateThemeControls(theme) {
        const controls = document.querySelectorAll('#themeToggle, #theme-toggle, .theme-toggle, [data-theme-toggle]');
        controls.forEach((control) => {
            if (!(control instanceof HTMLElement)) {
                return;
            }
            control.removeAttribute('onclick');
            control.setAttribute('aria-label', theme === 'dark' ? 'Включить светлую тему' : 'Включить тёмную тему');
            control.setAttribute('title', theme === 'dark' ? 'Включить светлую тему' : 'Включить тёмную тему');
            const icon = control.querySelector('[data-theme-icon], .theme-icon, .theme-toggle__symbol');
            if (icon instanceof HTMLElement) {
                if (icon.tagName === 'I') {
                    icon.className = theme === 'dark' ? 'bi bi-sun-fill' : 'bi bi-moon-fill';
                } else {
                    icon.textContent = theme === 'dark' ? '◑' : '◐';
                }
            }
            const label = control.querySelector('[data-theme-label]');
            if (label) label.textContent = theme === 'dark' ? 'Светлая тема' : 'Темная тема';
        });
    }
    function updateTimeInputsTheme(theme) {
        document.querySelectorAll('input[type="time"]').forEach((input) => {
            if (!(input instanceof HTMLElement)) {
                return;
            }
            if (theme === 'dark') {
                input.style.backgroundColor = '#333';
                input.style.color = '#fff';
                input.style.borderColor = '#555';
            } else {
                input.style.backgroundColor = '';
                input.style.color = '';
                input.style.borderColor = '';
            }
        });
    }
    function applyTheme(theme, persist = true, notify = true) {
        const nextTheme = validTheme(theme) ? theme : 'light';
        html.setAttribute('data-theme', nextTheme);
        if (document.body) {
            document.body.setAttribute('data-theme', nextTheme);
        }
        if (persist) {
            writeStoredTheme(nextTheme);
            writeCookieTheme(nextTheme);
        }
        updateThemeControls(nextTheme);
        updateTimeInputsTheme(nextTheme);
        if (notify) {
            document.dispatchEvent(new CustomEvent('themeChanged', { detail: { theme: nextTheme } }));
        }
    }
    applyTheme(initialTheme(), true, false);
    function bindThemeControls() {
        applyTheme(initialTheme(), true, false);
        document.querySelectorAll('#themeToggle, #theme-toggle, .theme-toggle, [data-theme-toggle]').forEach((control) => {
            if (!(control instanceof HTMLElement) || control.dataset.themeListener === 'true') {
                return;
            }
            control.dataset.themeListener = 'true';
            control.addEventListener('click', () => {
                const currentTheme = html.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
                applyTheme(currentTheme === 'dark' ? 'light' : 'dark');
            });
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bindThemeControls, {once: true});
    else bindThemeControls();
    window.addEventListener('storage', (event) => {
        if (storageKeys.includes(event.key || '') && validTheme(event.newValue)) {
            applyTheme(event.newValue, false);
        }
    });
    window.fitodomikTheme = {
        set: (theme) => applyTheme(theme),
        get: () => html.getAttribute('data-theme') === 'dark' ? 'dark' : 'light',
    };
})();
