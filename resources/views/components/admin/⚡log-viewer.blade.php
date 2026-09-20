<?php

use Livewire\Component;

new class extends Component
{
    public $filter = 'all'; // all, postfix, dovecot, security
    public $search = '';

    public function getLogsProperty()
    {
        $allLogs = [
            [
                'time' => now()->subSeconds(14)->format('H:i:s'),
                'service' => 'postfix/smtpd',
                'level' => 'INFO',
                'message' => 'connect from localhost[127.0.0.1]',
                'badge' => 'bg-blue-500/10 text-blue-400 border-blue-500/20',
            ],
            [
                'time' => now()->subSeconds(12)->format('H:i:s'),
                'service' => 'postfix/smtpd',
                'level' => 'INFO',
                'message' => 'SASL login authentication succeeded for user admin@perusahaan.net.id via dovecot',
                'badge' => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20',
            ],
            [
                'time' => now()->subSeconds(10)->format('H:i:s'),
                'service' => 'postfix/cleanup',
                'level' => 'INFO',
                'message' => 'message-id=<7b8c9d...2026@perusahaan.net.id> queued as 4YvK9L01',
                'badge' => 'bg-blue-500/10 text-blue-400 border-blue-500/20',
            ],
            [
                'time' => now()->subSeconds(8)->format('H:i:s'),
                'service' => 'opendkim',
                'level' => 'SECURITY',
                'message' => 'DKIM-Signature generated successfully using selector: default._domainkey.perusahaan.net.id',
                'badge' => 'bg-indigo-500/10 text-indigo-400 border-indigo-500/20',
            ],
            [
                'time' => now()->subSeconds(6)->format('H:i:s'),
                'service' => 'postfix/smtp',
                'level' => 'DELIVERY',
                'message' => 'to=<budi.santoso@perusahaan.net.id>, relay=local, status=sent (250 2.0.0 Ok: delivered to maildir /var/vmail/perusahaan.net.id/budi.santoso/)',
                'badge' => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20',
            ],
            [
                'time' => now()->subSeconds(4)->format('H:i:s'),
                'service' => 'dovecot',
                'level' => 'IMAP',
                'message' => 'imap-login: Login: user=<admin@perusahaan.net.id>, method=PLAIN, rip=127.0.0.1, lip=127.0.0.1, mpid=18920, TLS, session=<4F9A...>',
                'badge' => 'bg-cyan-500/10 text-cyan-400 border-cyan-500/20',
            ],
            [
                'time' => now()->subSeconds(2)->format('H:i:s'),
                'service' => 'postfix/anvil',
                'level' => 'RATE-LIMIT',
                'message' => 'statistics: max connection rate 1/60s for (smtp:127.0.0.1) at ' . now()->format('H:i:s'),
                'badge' => 'bg-slate-500/10 text-slate-400 border-slate-500/20',
            ],
        ];

        return collect($allLogs)->filter(function ($item) {
            if ($this->filter === 'postfix' && !str_contains($item['service'], 'postfix')) return false;
            if ($this->filter === 'dovecot' && !str_contains($item['service'], 'dovecot')) return false;
            if ($this->filter === 'security' && !in_array($item['level'], ['SECURITY', 'RATE-LIMIT'])) return false;

            if ($this->search && !str_contains(strtolower($item['message']), strtolower($this->search))) {
                return false;
            }
            return true;
        })->values();
    }

    public function render()
    {
        return view('components.admin.⚡log-viewer', [
            'logs' => $this->logs,
        ])->layout('layouts.app', ['title' => 'Mail Logs & Monitoring - Mail Portal']);
    }
};
?>

<div class="space-y-6 max-w-7xl mx-auto">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-extrabold text-white tracking-tight flex items-center gap-2">
                <i data-lucide="terminal" class="w-6 h-6 text-indigo-400"></i>
                Mail Logs & Engine Monitor (/var/log/mail.log)
            </h2>
            <p class="text-sm text-slate-400 mt-1">
                Pantau aktivitas lalu lintas pengiriman, autentikasi IMAP/SMTP, dan deteksi error secara real-time (Tahap 5.5).
            </p>
        </div>

        <div class="flex items-center gap-2">
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                Monitoring Daemon Active
            </span>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="p-4 rounded-2xl bg-slate-900/60 border border-slate-800 backdrop-blur-md flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div class="flex items-center gap-2">
            <button wire:click="$set('filter', 'all')" 
                    class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-all {{ $filter === 'all' ? 'bg-indigo-600 text-white' : 'bg-slate-800 text-slate-300 hover:text-white' }}">
                Semua Log
            </button>
            <button wire:click="$set('filter', 'postfix')" 
                    class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-all {{ $filter === 'postfix' ? 'bg-indigo-600 text-white' : 'bg-slate-800 text-slate-300 hover:text-white' }}">
                Postfix (MTA)
            </button>
            <button wire:click="$set('filter', 'dovecot')" 
                    class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-all {{ $filter === 'dovecot' ? 'bg-indigo-600 text-white' : 'bg-slate-800 text-slate-300 hover:text-white' }}">
                Dovecot (IMAP)
            </button>
            <button wire:click="$set('filter', 'security')" 
                    class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-all {{ $filter === 'security' ? 'bg-indigo-600 text-white' : 'bg-slate-800 text-slate-300 hover:text-white' }}">
                Security & DKIM
            </button>
        </div>

        <div class="w-full sm:w-64">
            <input type="text" wire:model.live.debounce.200ms="search" placeholder="Cari dalam log..." 
                   class="w-full px-3 py-1.5 bg-slate-950 border border-slate-700 rounded-lg text-xs text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500 font-mono">
        </div>
    </div>

    <!-- Log Console Window (Clean & Aligned Terminal Interface) -->
    <div class="rounded-2xl bg-slate-950 border border-slate-800/90 overflow-hidden shadow-2xl font-mono text-xs">
        <!-- Terminal Titlebar -->
        <div class="px-5 py-3 bg-slate-900/90 border-b border-slate-800 flex items-center justify-between">
            <div class="flex items-center gap-2 text-slate-400">
                <span class="w-3 h-3 rounded-full bg-rose-500/80 inline-block shadow-sm"></span>
                <span class="w-3 h-3 rounded-full bg-amber-500/80 inline-block shadow-sm"></span>
                <span class="w-3 h-3 rounded-full bg-emerald-500/80 inline-block shadow-sm"></span>
                <span class="text-slate-400 text-xs font-semibold ml-2 font-mono">bash &mdash; tail -f /var/log/mail.log</span>
            </div>
            <div class="flex items-center gap-3">
                <span class="flex items-center gap-1.5 text-[11px] text-emerald-400 font-semibold">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-ping"></span>
                    Live Streaming
                </span>
            </div>
        </div>

        <!-- Terminal Output Stream -->
        <div class="p-4 sm:p-5 space-y-1.5 overflow-x-auto max-h-[520px] bg-slate-950/90">
            @forelse($logs as $log)
            <div class="flex items-baseline gap-3.5 hover:bg-slate-900/70 px-3 py-2 rounded-lg transition-colors group">
                <!-- Timestamp: Fixed width for perfect alignment -->
                <span class="text-slate-500 select-none shrink-0 text-[11px] w-16 font-mono tracking-tight">
                    {{ $log['time'] }}
                </span>
                
                <!-- Service Tag: Fixed width pill badge -->
                <div class="w-32 shrink-0">
                    <span class="inline-block px-2 py-0.5 rounded text-[10px] font-bold tracking-tight uppercase border text-center w-full truncate {{ $log['badge'] }}">
                        {{ $log['service'] }}
                    </span>
                </div>

                <!-- Level Indicator -->
                <span class="text-[10px] font-bold px-1.5 py-0.5 rounded bg-slate-900 text-slate-400 border border-slate-800 shrink-0 w-16 text-center">
                    {{ $log['level'] }}
                </span>

                <!-- Message Content -->
                <div class="flex-1 text-slate-300 font-sans sm:font-mono text-xs leading-relaxed break-all select-text">
                    {{ $log['message'] }}
                </div>
            </div>
            @empty
            <div class="py-12 text-center text-slate-500 flex flex-col items-center justify-center gap-2">
                <i data-lucide="terminal" class="w-8 h-8 text-slate-700"></i>
                <p class="text-xs">Tidak ada baris log yang cocok dengan kriteria pencarian.</p>
            </div>
            @endforelse
        </div>
    </div>
</div>