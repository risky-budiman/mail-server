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

    <!-- Top Webmail Navigation Header -->
    <header class="h-16 px-4 sm:px-6 bg-slate-900/90 backdrop-blur-md border-b border-slate-800/80 flex items-center justify-between shrink-0 z-30 shadow-lg shadow-black/20">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-cyan-500 via-indigo-500 to-purple-600 flex items-center justify-center shadow-lg shadow-indigo-500/25 ring-1 ring-white/20">
                <i data-lucide="mail" class="w-4 h-4 text-white"></i>
            </div>
            <div class="flex items-center gap-2">
                <span class="font-extrabold text-white text-base tracking-tight flex items-center gap-1.5">
                    Mail<span class="text-transparent bg-clip-text bg-gradient-to-r from-cyan-400 to-indigo-400">IDS</span>
                </span>
                <span class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full bg-gradient-to-r from-cyan-500/10 to-indigo-500/10 text-cyan-400 border border-cyan-500/20 shadow-sm">
                    Webmail Client
                </span>
            </div>
        </div>

        <div class="flex items-center gap-3 sm:gap-4">
            <!-- Active Mailbox Pill -->
            <div class="px-3 py-1.5 rounded-xl bg-slate-950/80 border border-slate-800 flex items-center gap-2.5 shadow-inner">
                <div class="w-6 h-6 rounded-lg bg-gradient-to-tr from-cyan-500/20 to-indigo-500/20 border border-cyan-500/30 text-cyan-300 flex items-center justify-center font-bold text-xs">
                    {{ strtoupper(substr(auth('mailbox')->user()->email ?? 'U', 0, 1)) }}
                </div>
                <div class="flex flex-col text-left">
                    <span class="text-[11px] font-semibold text-slate-200 font-mono leading-none">
                        {{ auth('mailbox')->user()->email ?? 'user@domain.com' }}
                    </span>
                    <span class="text-[9px] text-emerald-400 font-medium flex items-center gap-1 leading-tight mt-0.5">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span> IMAP/SMTP Connected
                    </span>
                </div>
            </div>

            <div class="h-6 w-px bg-slate-800"></div>

            <form method="POST" action="{{ route('webmail.logout') }}" class="inline">
                @csrf
                <button type="submit" class="px-3 sm:px-3.5 py-1.5 rounded-xl bg-slate-800/80 hover:bg-rose-500/15 text-slate-300 hover:text-rose-400 text-xs font-semibold flex items-center gap-1.5 transition-all border border-slate-700/80 hover:border-rose-500/30 shadow-sm" title="Keluar dari Webmail">
                    <i data-lucide="log-out" class="w-3.5 h-3.5"></i>
                    <span class="hidden sm:inline">Keluar</span>
                </button>
            </form>
        </div>
    </header>

    <!-- Webmail Body Slot -->
    <main class="flex-1 p-3 sm:p-5 pb-6 sm:pb-8 mb-1 min-h-0 overflow-hidden flex flex-col">
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
