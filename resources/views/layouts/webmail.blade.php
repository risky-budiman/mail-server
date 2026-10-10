<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-slate-950 text-slate-100">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Webmail - MailIDS' }}</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <style>
        /* Email Rendered Canvas Styles (Gmail & Hostinger Webmail Standard) */
        .email-paper-canvas {
            background-color: #ffffff;
            color: #1e293b;
            border-radius: 1rem;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.25), 0 8px 10px -6px rgba(0, 0, 0, 0.15);
            padding: 1.75rem 2rem;
            min-height: 280px;
            overflow-x: auto;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            font-size: 0.95rem;
            line-height: 1.6;
        }
        .email-paper-canvas a {
            color: #2563eb;
            text-decoration: underline;
        }
        .email-paper-canvas a:hover {
            color: #1d4ed8;
        }

        .email-dark-canvas {
            background-color: #0b132b;
            color: #e2e8f0;
            border-radius: 1rem;
            border: 1px solid #1e293b;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.4);
            padding: 1.75rem 2rem;
            min-height: 280px;
            overflow-x: auto;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            font-size: 0.95rem;
            line-height: 1.6;
        }
        .email-dark-canvas a {
            color: #38bdf8;
            text-decoration: underline;
        }
        .email-dark-canvas a:hover {
            color: #7dd3fc;
        }

        .email-rendered-content {
            width: 100%;
        }
        .email-rendered-content p {
            margin-bottom: 0.75rem;
        }
        .email-rendered-content img {
            max-width: 100% !important;
            height: auto !important;
            display: inline-block;
        }
        .email-rendered-content table {
            max-width: 100%;
        }
        /* Hanya beri border pada table data bertanda khusus, JANGAN merusak layout tables */
        .email-rendered-content table[border="1"] td,
        .email-rendered-content table.data-table td {
            border: 1px solid #cbd5e1;
            padding: 0.5rem 0.75rem;
        }
        .email-dark-canvas .email-rendered-content table[border="1"] td,
        .email-dark-canvas .email-rendered-content table.data-table td {
            border-color: #334155;
        }
        .email-rendered-content hr {
            border: 0;
            border-top: 1px solid #e2e8f0;
            margin: 1.5rem 0;
        }
        .email-dark-canvas .email-rendered-content hr {
            border-top-color: #334155;
        }
    </style>
</head>
<body class="h-full antialiased font-sans bg-slate-950 text-slate-100 overflow-hidden flex flex-col relative">
    <!-- Sleek Top Loading Indicator during Livewire requests (Instant Feedback) -->
    <div id="livewire-global-loader" class="fixed top-0 left-0 right-0 h-1 bg-gradient-to-r from-cyan-400 via-indigo-500 to-purple-500 z-50 shadow-md shadow-cyan-500/40 transition-opacity duration-150 pointer-events-none opacity-0"></div>

    <!-- Webmail Body Slot (Edge-to-Edge full viewport) -->
    <main class="flex-1 w-full h-full min-h-0 overflow-hidden flex flex-col p-0 m-0">
        {{ $slot }}
    </main>

    @livewireScripts
    <script>
        (function() {
            let isRefreshing = false;
            let refreshTimer = null;
            window.refreshIcons = function() {
                if (isRefreshing) return;
                if (refreshTimer) clearTimeout(refreshTimer);
                refreshTimer = setTimeout(() => {
                    if (window.lucide && typeof window.lucide.createIcons === 'function') {
                        isRefreshing = true;
                        window.lucide.createIcons();
                        requestAnimationFrame(() => { isRefreshing = false; });
                    }
                }, 50);
            };

            document.addEventListener('DOMContentLoaded', window.refreshIcons);
            document.addEventListener('livewire:navigated', window.refreshIcons);

            document.addEventListener('livewire:init', () => {
                window.refreshIcons();

                const loader = document.getElementById('livewire-global-loader');
                if (typeof Livewire !== 'undefined' && Livewire.hook) {
                    Livewire.hook('request', ({ respond, fail }) => {
                        if (loader) loader.classList.remove('opacity-0');
                        respond(() => { if (loader) loader.classList.add('opacity-0'); });
                        fail(() => { if (loader) loader.classList.add('opacity-0'); });
                    });

                    Livewire.hook('commit', ({ succeed }) => {
                        succeed(() => {
                            window.refreshIcons();
                        });
                    });
                }
            });
        })();
    </script>
</body>
</html>
