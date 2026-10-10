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
        .email-rendered-content {
            color: #e2e8f0;
            font-size: 0.925rem;
            line-height: 1.65;
        }
        .email-rendered-content p {
            margin-bottom: 0.75rem;
        }
        .email-rendered-content a {
            color: #38bdf8;
            text-decoration: underline;
        }
        .email-rendered-content a:hover {
            color: #7dd3fc;
        }
        .email-rendered-content table {
            max-width: 100%;
            border-collapse: collapse;
            margin: 1rem 0;
        }
        .email-rendered-content td, .email-rendered-content th {
            padding: 0.5rem 0.75rem;
            border: 1px solid #334155;
        }
        .email-rendered-content img {
            max-width: 100%;
            height: auto;
            border-radius: 0.5rem;
        }
        .email-rendered-content b, .email-rendered-content strong {
            color: #ffffff;
        }
        .email-rendered-content hr {
            border-color: #334155;
            margin: 1.5rem 0;
        }
    </style>
</head>
<body class="h-full antialiased font-sans bg-slate-950 text-slate-100 overflow-hidden flex flex-col relative">
    <!-- Sleek Top Loading Indicator during Livewire requests -->
    <div wire:loading class="fixed top-0 left-0 right-0 h-1 bg-gradient-to-r from-cyan-400 via-indigo-500 to-purple-500 z-50 shadow-md shadow-cyan-500/30 animate-pulse"></div>

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

                if (typeof Livewire !== 'undefined' && Livewire.hook) {
                    Livewire.hook('commit', ({ succeed }) => {
                        succeed(() => {
                            window.refreshIcons();
                        });
                    });
                }
            });

            const lucideObserver = new MutationObserver(() => { if (!isRefreshing) window.refreshIcons(); });
            lucideObserver.observe(document.body, { childList: true, subtree: true });
        })();
    </script>
</body>
</html>
