@auth
    <script>
        @if ($themeMode = auth()->user()->theme_mode)
            localStorage.setItem('filament:theme:mode', @js($themeMode->value))
        @endif

        {
            // Filament's switcher also fires theme-changed on every page load, so only a different value is a choice.
            let currentTheme = localStorage.getItem('filament:theme:mode') ?? @js(filament()->getDefaultThemeMode()->value)

            window.addEventListener('theme-changed', ({ detail: theme }) => {
                if (theme === currentTheme) {
                    return
                }

                currentTheme = theme

                fetch(@js(route('filament.admin.theme')), {
                    method: 'POST',
                    keepalive: true,
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ theme }),
                })
            })
        }
    </script>
@endauth
