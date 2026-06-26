@php($themePreference = \App\Support\ThemePreference::current())

<script>
    (() => {
        const allowedThemes = ['dark', 'light'];
        const serverTheme = @js($themePreference);
        const preferenceUrl = @js(route('theme.preference', [], false));

        const normalizeTheme = (theme) => allowedThemes.includes(theme) ? theme : 'dark';
        const readCookieTheme = () => document.cookie
            .split('; ')
            .find((row) => row.startsWith('hardex_theme='))
            ?.split('=')[1];
        const storedTheme = localStorage.getItem('theme');
        const currentDocumentTheme = () => document.documentElement.classList.contains('dark') ? 'dark' : 'light';
        const preferredTheme = () => normalizeTheme(localStorage.getItem('theme') || readCookieTheme() || currentDocumentTheme() || serverTheme || 'dark');
        const initialTheme = preferredTheme();

        const applyTheme = (theme) => {
            const normalized = normalizeTheme(theme);

            localStorage.setItem('theme', normalized);
            document.cookie = `hardex_theme=${normalized}; path=/; max-age=31536000; samesite=lax`;
            document.documentElement.classList.toggle('dark', normalized === 'dark');
            window.dispatchEvent(new CustomEvent('hardex-theme-changed', { detail: { theme: normalized } }));
            window.dispatchEvent(new CustomEvent('buildmart-theme-changed'));

            return normalized;
        };

        const persistTheme = (theme) => {
            const token = document.querySelector('meta[name="csrf-token"]')?.content;

            if (! token) {
                return;
            }

            fetch(preferenceUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': token,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ theme }),
                keepalive: true,
            }).catch(() => {});
        };

        window.hardexTheme = {
            get() {
                return preferredTheme();
            },
            set(theme) {
                const normalized = applyTheme(theme);
                persistTheme(normalized);

                return normalized;
            },
            toggle() {
                return this.set(this.get() === 'dark' ? 'light' : 'dark');
            },
        };

        applyTheme(initialTheme);
        persistTheme(initialTheme);
        document.addEventListener('livewire:navigated', () => applyTheme(preferredTheme()));
    })();
</script>
