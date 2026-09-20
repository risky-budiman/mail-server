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
</head>
<body class="h-full antialiased font-sans bg-slate-950 text-slate-100 overflow-hidden flex flex-col">
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
    <main class="flex-1 p-3 sm:p-5 overflow-hidden flex flex-col min-w-0">
        {{ $slot }}
    </main>

    @livewireScripts
    <script>
        function refreshIcons() {
            if (window.lucide) window.lucide.createIcons();
        }
        document.addEventListener('livewire:navigated', refreshIcons);
        document.addEventListener('DOMContentLoaded', refreshIcons);
        document.addEventListener('livewire:init', () => {
            Livewire.hook('morph.updated', () => {
                refreshIcons();
            });
            Livewire.hook('commit', ({ succeed }) => {
                succeed(() => {
                    setTimeout(refreshIcons, 10);
                });
            });
        });
    </script>
</body>
</html>
