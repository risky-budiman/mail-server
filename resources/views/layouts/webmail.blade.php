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
    <!-- Top Webmail Navigation Header (Tanpa menu Admin Mail Server) -->
    <header class="h-14 px-4 sm:px-6 bg-slate-900 border-b border-slate-800 flex items-center justify-between shrink-0 z-20">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-xl bg-gradient-to-tr from-cyan-500 to-indigo-600 flex items-center justify-center shadow-md shadow-cyan-500/20">
                <i data-lucide="mail" class="w-4 h-4 text-white"></i>
            </div>
            <div>
                <span class="font-bold text-white text-sm tracking-tight flex items-center gap-1.5">
                    Mail<span class="text-cyan-400">IDS</span>
                    <span class="text-[9px] font-mono px-1.5 py-0.2 rounded bg-cyan-500/20 text-cyan-300 border border-cyan-500/30">WEBMAIL</span>
                </span>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <div class="text-xs text-slate-300 font-medium flex items-center gap-2">
                <div class="w-7 h-7 rounded-full bg-cyan-500/20 border border-cyan-500/30 text-cyan-300 flex items-center justify-center font-bold text-xs">
                    {{ strtoupper(substr(auth('mailbox')->user()->email ?? 'U', 0, 1)) }}
                </div>
                <span class="hidden sm:inline font-mono text-cyan-300">{{ auth('mailbox')->user()->email ?? 'user@domain.com' }}</span>
            </div>

            <div class="h-5 w-px bg-slate-800"></div>

            <form method="POST" action="{{ route('webmail.logout') }}" class="inline">
                @csrf
                <button type="submit" class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-rose-500/20 text-slate-300 hover:text-rose-400 text-xs font-semibold flex items-center gap-1.5 transition-all border border-slate-700">
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
