@php($themePreference = \App\Support\ThemePreference::current())

<script>
    (() => {
        const allowedThemes = ['dark', 'light'];
        const serverTheme = @js($themePreference);
        const preferenceUrl = @js(route('theme.preference', [], false));

        const normalizeTheme = (theme) => allowedThemes.includes(theme) ? theme : 'dark';
        const cookieTheme = document.cookie
            .split('; ')
            .find((row) => row.startsWith('hardex_theme='))
            ?.split('=')[1];
        const storedTheme = localStorage.getItem('theme');
        const initialTheme = normalizeTheme(storedTheme || cookieTheme || serverTheme || 'dark');

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
                return normalizeTheme(localStorage.getItem('theme') || cookieTheme || serverTheme || 'dark');
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
    })();
</script>
