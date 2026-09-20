<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-slate-950 text-slate-100">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'MailIDS - Portal Mail Server' }}</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full antialiased font-sans flex flex-col md:flex-row bg-slate-950 text-slate-100 overflow-hidden" 
      x-data="{ mobileMenuOpen: false }">
    
    <!-- Mobile Header & Toggle Button (Shown only on small screens) -->
    <div class="md:hidden h-14 px-4 bg-slate-900 border-b border-slate-800 flex items-center justify-between z-30 shrink-0">
        <div class="flex items-center gap-2.5">
            <div class="w-8 h-8 rounded-lg bg-gradient-to-tr from-indigo-600 to-cyan-400 flex items-center justify-center shadow-md">
                <i data-lucide="mail" class="w-4 h-4 text-white"></i>
            </div>
            <span class="font-bold text-white text-sm">Mail<span class="text-indigo-400">IDS</span></span>
        </div>
        <button @click="mobileMenuOpen = !mobileMenuOpen" class="p-2 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800">
            <i data-lucide="menu" class="w-5 h-5"></i>
        </button>
    </div>

    <!-- Mobile Backdrop -->
    <div x-show="mobileMenuOpen" @click="mobileMenuOpen = false" 
         x-transition:enter="transition-opacity ease-linear duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition-opacity ease-linear duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 bg-black/60 z-40 md:hidden" style="display: none;"></div>

    <!-- Sidebar Navigation (Responsive Drawer on Mobile, Static on Desktop) -->
    <aside :class="mobileMenuOpen ? 'translate-x-0' : '-translate-x-full md:translate-x-0'"
           class="fixed md:static inset-y-0 left-0 w-64 bg-slate-900/95 md:bg-slate-900/90 border-r border-slate-800/80 backdrop-blur-xl flex flex-col justify-between shrink-0 z-50 md:z-30 transition-transform duration-200 ease-in-out">
        <div class="flex-1 overflow-y-auto">
            <!-- App Brand / Logo -->
            <div class="h-16 px-5 flex items-center justify-between border-b border-slate-800/60 bg-gradient-to-r from-indigo-950/40 via-slate-900/50 to-transparent">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-indigo-600 via-indigo-500 to-cyan-400 flex items-center justify-center shadow-lg shadow-indigo-500/20 ring-1 ring-white/20">
                        <i data-lucide="mail" class="w-4 h-4 text-white"></i>
                    </div>
                    <div>
                        <h1 class="text-sm font-bold tracking-tight text-white flex items-center gap-1">
                            Mail<span class="text-indigo-400">IDS</span>
                            <span class="text-[9px] uppercase font-mono px-1.5 py-0.2 rounded bg-indigo-500/20 text-indigo-300 border border-indigo-500/30">SPA</span>
                        </h1>
                        <p class="text-[10px] text-slate-400 font-medium">Mail Engine Portal</p>
                    </div>
                </div>
                <!-- Close mobile menu button -->
                <button @click="mobileMenuOpen = false" class="md:hidden p-1.5 text-slate-400 hover:text-white">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>

            <!-- Nav Links -->
            <nav class="p-3 space-y-1">
                <div class="px-3 py-2 text-[10px] font-bold text-slate-400 uppercase tracking-wider">
                    Core Portal
                </div>

                <a href="{{ route('admin.dashboard') }}" wire:navigate @click="mobileMenuOpen = false"
                   class="flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-medium transition-all {{ request()->routeIs('admin.dashboard') ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/30 font-semibold' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                    <i data-lucide="layout-dashboard" class="w-4 h-4 shrink-0"></i>
                    <span>Dashboard Stats</span>
                </a>

                <a href="{{ route('admin.domains') }}" wire:navigate @click="mobileMenuOpen = false"
                   class="flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-medium transition-all {{ request()->routeIs('admin.domains') ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/30 font-semibold' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                    <i data-lucide="globe" class="w-4 h-4 shrink-0"></i>
                    <span>Domain Bisnis</span>
                </a>

                <a href="{{ route('admin.users') }}" wire:navigate @click="mobileMenuOpen = false"
                   class="flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-medium transition-all {{ request()->routeIs('admin.users') ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/30 font-semibold' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                    <i data-lucide="users" class="w-4 h-4 shrink-0"></i>
                    <span>Akun Email (Mailbox)</span>
                </a>

                <a href="{{ route('admin.aliases') }}" wire:navigate @click="mobileMenuOpen = false"
                   class="flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-medium transition-all {{ request()->routeIs('admin.aliases') ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/30 font-semibold' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                    <i data-lucide="forward" class="w-4 h-4 shrink-0"></i>
                    <span>Alias & Forwarding</span>
                </a>

                <a href="{{ route('admin.dns-helper') }}" wire:navigate @click="mobileMenuOpen = false"
                   class="flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-medium transition-all {{ request()->routeIs('admin.dns-helper') ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/30 font-semibold' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                    <i data-lucide="shield-check" class="w-4 h-4 shrink-0"></i>
                    <span>DNS & Security Guide</span>
                </a>

                <a href="{{ route('admin.mail-tester') }}" wire:navigate @click="mobileMenuOpen = false"
                   class="flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-medium transition-all {{ request()->routeIs('admin.mail-tester') ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/30 font-semibold' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                    <i data-lucide="check-check" class="w-4 h-4 shrink-0"></i>
                    <span>Deliverability Test (10/10)</span>
                </a>

                <a href="{{ route('admin.installer') }}" wire:navigate @click="mobileMenuOpen = false"
                   class="flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-medium transition-all {{ request()->routeIs('admin.installer') ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/30 font-semibold' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                    <i data-lucide="cpu" class="w-4 h-4 shrink-0 text-cyan-400"></i>
                    <span>1-Click Server Installer</span>
                </a>

                <a href="{{ route('admin.logs') }}" wire:navigate @click="mobileMenuOpen = false"
                   class="flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-medium transition-all {{ request()->routeIs('admin.logs') ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/30 font-semibold' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                    <i data-lucide="terminal" class="w-4 h-4 shrink-0"></i>
                    <span>Mail Logs Monitor</span>
                </a>

                <a href="{{ route('admin.profile') }}" wire:navigate @click="mobileMenuOpen = false"
                   class="flex items-center gap-3 px-3 py-2 rounded-lg text-xs font-medium transition-all {{ request()->routeIs('admin.profile') ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/30 font-semibold' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                    <i data-lucide="user-cog" class="w-4 h-4 shrink-0 text-indigo-400"></i>
                    <span>Keamanan & Akun Admin</span>
                </a>

                <div class="pt-3 px-3 py-2 text-[10px] font-bold text-slate-400 uppercase tracking-wider">
                    Webmail Client
                </div>

                <a href="{{ route('webmail.client') }}" wire:navigate @click="mobileMenuOpen = false"
                   class="flex items-center justify-between px-3 py-2 rounded-lg text-xs font-medium transition-all {{ request()->routeIs('webmail.client') ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/30 font-semibold' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                    <div class="flex items-center gap-3">
                        <i data-lucide="inbox" class="w-4 h-4 shrink-0"></i>
                        <span>Webmail Inbox</span>
                    </div>
                    <span class="px-1.5 py-0.2 text-[9px] font-bold rounded-full bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">Live</span>
                </a>
            </nav>
        </div>

        <!-- Engine Status Footer -->
        <div class="p-3.5 border-t border-slate-800/60 bg-slate-950/40 shrink-0">
            <div class="flex items-center gap-2.5">
                <div class="relative flex h-2.5 w-2.5">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-emerald-500"></span>
                </div>
                <div class="text-[11px]">
                    <p class="font-medium text-slate-200">Database Engine</p>
                    <p class="text-[10px] text-slate-400">Postfix & Dovecot Ready</p>
                </div>
            </div>
        </div>
    </aside>

    <!-- Main Content Area -->
    <main class="flex-1 flex flex-col min-w-0 bg-slate-950 overflow-y-auto">
        <!-- Top Header Bar -->
        <header class="h-14 md:h-16 px-4 md:px-8 border-b border-slate-800/60 flex items-center justify-between bg-slate-900/40 backdrop-blur-md sticky top-0 z-20 shrink-0">
            <div class="flex items-center gap-2">
                <span class="hidden sm:inline text-xs font-medium text-slate-400">Mode:</span>
                @if(app()->environment('production'))
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-medium bg-emerald-500/10 text-emerald-300 border border-emerald-500/25">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                        <span>Production Live</span>
                    </span>
                @else
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-medium bg-indigo-500/10 text-indigo-300 border border-indigo-500/20">
                        <span class="w-1.5 h-1.5 rounded-full bg-indigo-400"></span>
                        <span>Development</span>
                    </span>
                @endif
            </div>

            <div class="flex items-center gap-3">
                <a href="{{ route('webmail.client') }}" wire:navigate 
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-indigo-600 hover:bg-indigo-500 text-white transition-all shadow-md shadow-indigo-600/20">
                    <i data-lucide="send" class="w-3.5 h-3.5"></i>
                    <span>Webmail</span>
                </a>
                <a href="{{ route('admin.profile') }}" wire:navigate 
                   class="flex items-center gap-2 text-xs text-slate-300 hover:text-white px-2.5 py-1.5 rounded-lg hover:bg-slate-800/60 transition-all border border-transparent hover:border-slate-700">
                    <div class="w-7 h-7 rounded-full bg-gradient-to-tr from-indigo-600 to-cyan-500 flex items-center justify-center font-bold text-xs text-white shadow-sm ring-1 ring-white/20">
                        {{ strtoupper(substr(auth()->user()->name ?? 'AD', 0, 2)) }}
                    </div>
                    <div class="hidden md:block text-left">
                        <p class="font-medium text-white leading-tight">{{ auth()->user()->name ?? 'Super Admin' }}</p>
                        <p class="text-[10px] text-slate-400 leading-tight">Pengaturan Akun</p>
                    </div>
                </a>

                <form method="POST" action="{{ route('admin.logout') }}" class="inline">
                    @csrf
                    <button type="submit" class="p-2 rounded-lg text-slate-400 hover:text-rose-400 hover:bg-slate-800/80 transition-all" title="Logout Admin">
                        <i data-lucide="log-out" class="w-4 h-4"></i>
                    </button>
                </form>
            </div>
        </header>

        <!-- Dynamic Slot View (Responsive padding & scroll) -->
        <div class="flex-1 p-4 sm:p-6 lg:p-8 min-w-0">
            {{ $slot }}
        </div>
    </main>

    @livewireScripts
    <script>
        function refreshIcons() {
            if (window.lucide) {
                window.lucide.createIcons();
            }
        }
        document.addEventListener('livewire:navigated', refreshIcons);
        document.addEventListener('DOMContentLoaded', refreshIcons);
        document.addEventListener('livewire:initialized', () => {
            refreshIcons();
            Livewire.hook('morph.updated', () => {
                refreshIcons();
            });
            Livewire.hook('commit', ({ succeed }) => {
                succeed(() => {
                    refreshIcons();
                });
            });
        });
    </script>
</body>
</html>
