<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title inertia>{{ config('app.name', 'AnatoLab') }}</title>

    {{--
      Set the theme class before first paint. Inline and blocking on purpose:
      deferring it to app.ts guarantees a flash of the wrong theme on every
      cold load. Kept to a single expression so the CSP hash stays stable.
    --}}
    <script>
        (function () {
            try {
                var stored = localStorage.getItem('anatolab.theme');
                var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                if (stored === 'dark' || (stored !== 'light' && prefersDark)) {
                    document.documentElement.classList.add('dark');
                }
            } catch (e) {
                /* Private mode or blocked storage: light theme is a fine default. */
            }
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.ts'])
    @inertiaHead
</head>
<body class="min-h-screen antialiased">
    @inertia
</body>
</html>
