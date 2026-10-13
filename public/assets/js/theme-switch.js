(function () {
    const STORAGE_KEY = 'tabler-theme';
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)');

    function storedTheme() {
        try {
            return localStorage.getItem(STORAGE_KEY) || 'auto';
        } catch (_e) {
            return 'auto';
        }
    }

    function resolveTheme(mode) {
        if (mode === 'auto') {
            return prefersDark.matches ? 'dark' : 'light';
        }
        return mode === 'dark' ? 'dark' : 'light';
    }

    function applyTheme(mode) {
        const resolved = resolveTheme(mode);
        if (resolved === 'dark') {
            document.documentElement.setAttribute('data-bs-theme', 'dark');
        } else {
            document.documentElement.removeAttribute('data-bs-theme');
        }
        document.querySelectorAll('[data-theme-switch]').forEach(function (root) {
            root.querySelectorAll('[data-theme-value]').forEach(function (btn) {
                const active = btn.getAttribute('data-theme-value') === mode;
                btn.classList.toggle('is-active', active);
                btn.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
        });
    }

    function setTheme(mode) {
        if (mode !== 'light' && mode !== 'dark' && mode !== 'auto') {
            mode = 'auto';
        }
        try {
            localStorage.setItem(STORAGE_KEY, mode);
        } catch (_e) {}
        applyTheme(mode);
    }

    document.addEventListener('click', function (event) {
        const btn = event.target.closest('[data-theme-value]');
        if (!btn) {
            return;
        }
        event.preventDefault();
        setTheme(btn.getAttribute('data-theme-value'));
    });

    prefersDark.addEventListener('change', function () {
        if (storedTheme() === 'auto') {
            applyTheme('auto');
        }
    });

    applyTheme(storedTheme());
})();
