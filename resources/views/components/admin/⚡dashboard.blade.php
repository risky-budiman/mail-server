<?php

use Livewire\Component;
use App\Models\VirtualDomain;
use App\Models\VirtualUser;
use App\Models\VirtualAlias;

new class extends Component
{
    public function render()
    {
        $domainCount = VirtualDomain::count();
        $userCount = VirtualUser::count();
        $aliasCount = VirtualAlias::count();
        $activeUserCount = VirtualUser::where('is_active', true)->count();
        
        $totalStorageBytes = VirtualUser::sum('quota_bytes');
        $usedStorageBytes = VirtualUser::sum('used_bytes');
        
        $storageUsagePercent = $totalStorageBytes > 0 
            ? round(($usedStorageBytes / $totalStorageBytes) * 100, 1) 
            : 0;

        $recentUsers = VirtualUser::with('domain')
            ->latest()
            ->take(5)
            ->get();

        return view('components.admin.⚡dashboard', [
            'domainCount' => $domainCount,
            'userCount' => $userCount,
            'aliasCount' => $aliasCount,
            'activeUserCount' => $activeUserCount,
            'totalStorageGB' => round($totalStorageBytes / (1024 * 1024 * 1024), 2),
            'usedStorageGB' => round($usedStorageBytes / (1024 * 1024 * 1024), 2),
            'storageUsagePercent' => $storageUsagePercent,
            'recentUsers' => $recentUsers,
        ])->layout('layouts.app', ['title' => 'Dashboard - Mail Engine Portal']);
    }
};
?>

<div class="space-y-8 max-w-7xl mx-auto">
    <!-- Header Page -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-extrabold text-white tracking-tight flex items-center gap-2">
                <i data-lucide="activity" class="w-6 h-6 text-indigo-400"></i>
                Overview Mail Server Engine
            </h2>
            <p class="text-sm text-slate-400 mt-1">
                Pantau kapasitas virtual mailbox, domain terdaftar, dan status siap pakai Postfix & Dovecot.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('admin.users') }}" wire:navigate class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-sm font-semibold transition-all shadow-lg shadow-indigo-600/25">
                <i data-lucide="user-plus" class="w-4 h-4"></i>
                Tambah Mailbox Baru
            </a>
        </div>
    </div>

    <!-- Stats Grid Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
        <!-- Card 1: Virtual Domains -->
        <div class="p-5 rounded-2xl bg-slate-900/60 border border-slate-800 backdrop-blur-md relative overflow-hidden group hover:border-slate-700 transition-all">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-slate-400 uppercase tracking-wider">Virtual Domains</p>
                    <p class="text-3xl font-extrabold text-white mt-2">{{ $domainCount }}</p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-indigo-500/10 border border-indigo-500/20 flex items-center justify-center text-indigo-400">
                    <i data-lucide="globe" class="w-6 h-6"></i>
                </div>
            </div>
            <div class="mt-4 flex items-center gap-2 text-xs text-slate-400">
                <span class="text-emerald-400 font-semibold flex items-center gap-0.5">
                    <i data-lucide="check-circle" class="w-3.5 h-3.5"></i> Siap routing
                </span>
                <span>ke Postfix MX</span>
            </div>
        </div>

        <!-- Card 2: Active Mailboxes -->
        <div class="p-5 rounded-2xl bg-slate-900/60 border border-slate-800 backdrop-blur-md relative overflow-hidden group hover:border-slate-700 transition-all">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-slate-400 uppercase tracking-wider">Total Mailboxes</p>
                    <p class="text-3xl font-extrabold text-white mt-2">{{ $userCount }}</p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-blue-500/10 border border-blue-500/20 flex items-center justify-center text-blue-400">
                    <i data-lucide="users" class="w-6 h-6"></i>
                </div>
            </div>
            <div class="mt-4 flex items-center gap-2 text-xs text-slate-400">
                <span class="text-emerald-400 font-medium">{{ $activeUserCount }} Aktif</span>
                <span>• Dovecot IMAP sync</span>
            </div>
        </div>

        <!-- Card 3: Aliases / Forwarders -->
        <div class="p-5 rounded-2xl bg-slate-900/60 border border-slate-800 backdrop-blur-md relative overflow-hidden group hover:border-slate-700 transition-all">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-slate-400 uppercase tracking-wider">Aliases & Forward</p>
                    <p class="text-3xl font-extrabold text-white mt-2">{{ $aliasCount }}</p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-cyan-500/10 border border-cyan-500/20 flex items-center justify-center text-cyan-400">
                    <i data-lucide="forward" class="w-6 h-6"></i>
                </div>
            </div>
            <div class="mt-4 flex items-center gap-2 text-xs text-slate-400">
                <span class="text-cyan-400 font-medium">Virtual alias maps</span>
            </div>
        </div>

        <!-- Card 4: Total Storage Usage -->
        <div class="p-5 rounded-2xl bg-slate-900/60 border border-slate-800 backdrop-blur-md relative overflow-hidden group hover:border-slate-700 transition-all">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-slate-400 uppercase tracking-wider">Storage Terpakai</p>
                    <p class="text-3xl font-extrabold text-white mt-2">{{ $usedStorageGB }} <span class="text-base font-medium text-slate-400">/ {{ $totalStorageGB }} GB</span></p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-amber-500/10 border border-amber-500/20 flex items-center justify-center text-amber-400">
                    <i data-lucide="hard-drive" class="w-6 h-6"></i>
                </div>
            </div>
            <!-- Progress Bar -->
            <div class="mt-3">
                <div class="w-full h-2 bg-slate-800 rounded-full overflow-hidden">
                    <div class="h-full bg-gradient-to-r from-indigo-500 to-amber-500 rounded-full" style="width: {{ $storageUsagePercent }}%"></div>
                </div>
                <div class="flex justify-between items-center text-[11px] text-slate-400 mt-1">
                    <span>{{ $storageUsagePercent }}% Terpakai</span>
                    <span>Format Maildir</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content Section: Recent Mailboxes & System Status -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Recent Mailboxes Table (2 Cols) -->
        <div class="lg:col-span-2 p-6 rounded-2xl bg-slate-900/60 border border-slate-800 backdrop-blur-md space-y-4">
            <div class="flex items-center justify-between border-b border-slate-800 pb-4">
                <div>
                    <h3 class="text-base font-bold text-white flex items-center gap-2">
                        <i data-lucide="mail-check" class="w-4 h-4 text-indigo-400"></i>
                        Daftar Akun Mailbox Terbaru
                    </h3>
                    <p class="text-xs text-slate-400 mt-0.5">Akun yang tersinkronisasi otomatis dengan tabel virtual_users</p>
                </div>
                <a href="{{ route('admin.users') }}" wire:navigate class="text-xs font-medium text-indigo-400 hover:text-indigo-300 flex items-center gap-1">
                    Lihat Semua <i data-lucide="arrow-right" class="w-3 h-3"></i>
                </a>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="text-slate-400 border-b border-slate-800/80">
                            <th class="py-3 px-2 font-medium">Alamat Email</th>
                            <th class="py-3 px-2 font-medium">Domain</th>
                            <th class="py-3 px-2 font-medium">Penggunaan Kuota</th>
                            <th class="py-3 px-2 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/50 text-slate-200">
                        @forelse($recentUsers as $user)
                        <tr class="hover:bg-slate-800/30 transition-colors">
                            <td class="py-3 px-2 font-semibold text-white flex items-center gap-2">
                                <div class="w-7 h-7 rounded-lg bg-indigo-500/20 text-indigo-300 flex items-center justify-center font-bold text-[11px]">
                                    {{ strtoupper(substr($user->email, 0, 1)) }}
                                </div>
                                <div>
                                    <p>{{ $user->email }}</p>
                                    <p class="text-[10px] text-slate-400 font-normal">{{ $user->name ?? 'User Mailbox' }}</p>
                                </div>
                            </td>
                            <td class="py-3 px-2 text-slate-300">
                                <span class="px-2 py-0.5 rounded bg-slate-800 text-slate-300 text-[11px] font-mono border border-slate-700">
                                    {{ $user->domain->name ?? '-' }}
                                </span>
                            </td>
                            <td class="py-3 px-2">
                                <div class="w-28">
                                    <div class="flex justify-between text-[10px] mb-1 text-slate-400">
                                        <span>{{ round($user->used_bytes / (1024*1024), 0) }}MB</span>
                                        <span>{{ $user->formatted_quota }}</span>
                                    </div>
                                    <div class="w-full h-1.5 bg-slate-800 rounded-full overflow-hidden">
                                        <div class="h-full {{ $user->quota_usage_percent > 80 ? 'bg-rose-500' : 'bg-indigo-500' }}" style="width: {{ $user->quota_usage_percent }}%"></div>
                                    </div>
                                </div>
                            </td>
                            <td class="py-3 px-2">
                                @if($user->is_active)
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                                        <span class="w-1 h-1 rounded-full bg-emerald-400"></span> Aktif
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium bg-rose-500/10 text-rose-400 border border-rose-500/20">
                                        Nonaktif
                                    </span>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="4" class="py-6 text-center text-slate-400">Belum ada user terdaftar.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Technical Engine Readiness (1 Col) -->
        <div class="p-6 rounded-2xl bg-slate-900/60 border border-slate-800 backdrop-blur-md space-y-4">
            <div class="border-b border-slate-800 pb-3">
                <h3 class="text-base font-bold text-white flex items-center gap-2">
                    <i data-lucide="shield-check" class="w-4 h-4 text-emerald-400"></i>
                    Kesiapan Core Engine
                </h3>
                <p class="text-xs text-slate-400 mt-0.5">Integrasi Postfix & Dovecot</p>
            </div>

            <div class="space-y-3 text-xs">
                <div class="p-3 rounded-xl bg-slate-800/50 border border-slate-700/60 flex items-start gap-3">
                    <div class="w-6 h-6 rounded-lg bg-emerald-500/20 text-emerald-400 flex items-center justify-center shrink-0 mt-0.5">
                        <i data-lucide="check" class="w-3.5 h-3.5"></i>
                    </div>
                    <div>
                        <p class="font-semibold text-white">Database Virtual Mailbox</p>
                        <p class="text-[11px] text-slate-400 mt-0.5">Tabel virtual_domains, virtual_users, dan virtual_aliases sudah aktif & terstruktur.</p>
                    </div>
                </div>

                <div class="p-3 rounded-xl bg-slate-800/50 border border-slate-700/60 flex items-start gap-3">
                    <div class="w-6 h-6 rounded-lg bg-indigo-500/20 text-indigo-400 flex items-center justify-center shrink-0 mt-0.5">
                        <i data-lucide="cpu" class="w-3.5 h-3.5"></i>
                    </div>
                    <div>
                        <p class="font-semibold text-white">Livewire 4 SPA Active</p>
                        <p class="text-[11px] text-slate-400 mt-0.5">Pindah halaman tanpa reload (wire:navigate) untuk navigasi kilat.</p>
                    </div>
                </div>

                <div class="p-3 rounded-xl bg-slate-800/50 border border-slate-700/60 flex items-start gap-3">
                    <div class="w-6 h-6 rounded-lg bg-amber-500/20 text-amber-400 flex items-center justify-center shrink-0 mt-0.5">
                        <i data-lucide="server" class="w-3.5 h-3.5"></i>
                    </div>
                    <div>
                        <p class="font-semibold text-white">Tahap Selanjutnya: VPS Engine</p>
                        <p class="text-[11px] text-slate-400 mt-0.5">Postfix & Dovecot akan membaca data dari tabel ini saat server Linux dihubungkan.</p>
                    </div>
                </div>
            </div>

            <div class="pt-2">
                <a href="{{ route('webmail.client') }}" wire:navigate class="w-full py-2.5 px-4 rounded-xl bg-gradient-to-r from-indigo-600 to-indigo-700 hover:from-indigo-500 hover:to-indigo-600 text-white font-semibold text-xs flex items-center justify-center gap-2 shadow-lg shadow-indigo-600/20 transition-all">
                    <i data-lucide="inbox" class="w-4 h-4"></i>
                    Uji Coba Webmail Client
                </a>
            </div>
        </div>
    </div>
</div>