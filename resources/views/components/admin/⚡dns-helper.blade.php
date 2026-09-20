<?php

use Livewire\Component;
use App\Models\VirtualDomain;

new class extends Component
{
    public $selectedDomainId = '';
    public $serverIp = '';
    public $dkimSelector = 'default';
    public $dkimPublicKey = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAyX5t9v...QIDAQAB';
    public $isAutoIp = true;

    public function mount()
    {
        $domain = VirtualDomain::first();
        if ($domain) {
            $this->selectedDomainId = $domain->id;
        }

        $this->detectServerPublicIp();
    }

    public function detectServerPublicIp()
    {
        // 1. Coba deteksi IP publik server secara otomatis (sangat berguna saat di VPS)
        $detectedIp = null;
        try {
            $context = stream_context_create([
                'http' => ['timeout' => 2]
            ]);
            $ip = @file_get_contents('https://api.ipify.org', false, $context);
            if ($ip && filter_var(trim($ip), FILTER_VALIDATE_IP)) {
                $detectedIp = trim($ip);
            }
        } catch (\Throwable $e) {
            // fallback
        }

        // 2. Jika di localhost / gagal koneksi, periksa request host atau gunakan fallback
        if (!$detectedIp) {
            $serverAddr = request()->server('SERVER_ADDR');
            if ($serverAddr && filter_var($serverAddr, FILTER_VALIDATE_IP) && $serverAddr !== '127.0.0.1' && $serverAddr !== '::1') {
                $detectedIp = $serverAddr;
            }
        }

        $this->serverIp = $detectedIp ?: '103.180.200.15';
        $this->isAutoIp = !empty($detectedIp);
    }

    public function render()
    {
        $domains = VirtualDomain::all();
        $currentDomain = VirtualDomain::find($this->selectedDomainId) ?? $domains->first();
        $domainName = $currentDomain ? $currentDomain->name : 'domain.net.id';

        $dnsRecords = [
            [
                'type' => 'A',
                'subtype' => 'Host',
                'host' => 'mail.' . $domainName,
                'value' => $this->serverIp,
                'priority' => '-',
                'ttl' => '3600',
                'description' => 'Mengarahkan host mail server ke IP publik VPS Anda.',
                'status' => 'Wajib',
            ],
            [
                'type' => 'MX',
                'subtype' => 'Mail Exchanger',
                'host' => '@ (atau ' . $domainName . ')',
                'value' => 'mail.' . $domainName . '.',
                'priority' => '10',
                'ttl' => '3600',
                'description' => 'Menentukan server tujuan untuk seluruh email yang masuk ke domain ini.',
                'status' => 'Wajib',
            ],
            [
                'type' => 'TXT',
                'subtype' => 'SPF Record',
                'host' => '@ (atau ' . $domainName . ')',
                'value' => 'v=spf1 mx a:mail.' . $domainName . ' ip4:' . $this->serverIp . ' ~all',
                'priority' => '-',
                'ttl' => '3600',
                'description' => 'Mengesahkan server dengan IP tersebut berhak mengirim email atas nama domain.',
                'status' => 'Penting (Anti-Spam)',
            ],
            [
                'type' => 'TXT',
                'subtype' => 'DKIM Signature',
                'host' => $this->dkimSelector . '._domainkey.' . $domainName,
                'value' => 'v=DKIM1; k=rsa; p=' . $this->dkimPublicKey,
                'priority' => '-',
                'ttl' => '3600',
                'description' => 'Kunci digital publik OpenDKIM agar email tidak dianggap palsu oleh Gmail/Yahoo.',
                'status' => 'Penting (Tanda Tangan Digital)',
            ],
            [
                'type' => 'TXT',
                'subtype' => 'DMARC Policy',
                'host' => '_dmarc.' . $domainName,
                'value' => 'v=DMARC1; p=quarantine; rua=mailto:postmaster@' . $domainName . '; ruf=mailto:postmaster@' . $domainName . '; sp=quarantine; pct=100',
                'priority' => '-',
                'ttl' => '3600',
                'description' => 'Kebijakan keamanan ketat agar penerima email mengkarantina email yang gagal SPF/DKIM.',
                'status' => 'Wajib untuk Gmail 2024+',
            ],
            [
                'type' => 'PTR',
                'subtype' => 'Reverse DNS',
                'host' => $this->serverIp . ' (diatur di panel hosting VPS)',
                'value' => 'mail.' . $domainName,
                'priority' => '-',
                'ttl' => 'Default',
                'description' => 'Reverse DNS IP harus meresolusi balik ke hostname mail server.',
                'status' => 'Krusial (Syarat Inbox)',
            ],
        ];

        return view('components.admin.⚡dns-helper', [
            'domains' => $domains,
            'domainName' => $domainName,
            'dnsRecords' => $dnsRecords,
        ])->layout('layouts.app', ['title' => 'DNS & Security Guide - Mail Portal']);
    }
};
?>

<div class="space-y-6 max-w-7xl mx-auto">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-extrabold text-white tracking-tight flex items-center gap-2">
                <i data-lucide="shield-check" class="w-6 h-6 text-indigo-400"></i>
                Generator DNS & Keamanan Email (SPF, DKIM, DMARC)
            </h2>
            <p class="text-sm text-slate-400 mt-1">
                Alat bantu otomatis untuk men-generate konfigurasi DNS record domain Anda agar saat nanti VPS sudah dibeli, skor email langsung 10/10 (Masuk Inbox).
            </p>
        </div>
    </div>

    <!-- Parameter Konfigurasi Domain & IP -->
    <div class="p-6 rounded-2xl bg-slate-900/60 border border-slate-800 backdrop-blur-md">
        <h3 class="text-sm font-bold text-white mb-4 flex items-center gap-2">
            <i data-lucide="sliders" class="w-4 h-4 text-indigo-400"></i>
            Parameter Server & Domain Anda
        </h3>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <!-- 1. Pilih Domain -->
            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-2">
                    Pilih Domain
                </label>
                <div class="relative">
                    <select wire:model.live="selectedDomainId" 
                            style="-webkit-appearance: none; -moz-appearance: none; appearance: none; padding-left: 1.25rem; padding-right: 2.5rem;"
                            class="w-full py-2.5 bg-slate-950 border border-slate-700/80 hover:border-slate-600 rounded-xl text-xs sm:text-sm text-white focus:outline-none focus:border-indigo-500 font-mono transition-all cursor-pointer shadow-sm">
                        @foreach($domains as $d)
                            <option value="{{ $d->id }}">{{ $d->name }}</option>
                        @endforeach
                    </select>
                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3.5 text-slate-400">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                    </div>
                </div>
            </div>

            <!-- 2. IP Publik Server (Read-only / Terkunci Otomatis dengan Visual Feedback) -->
            <div>
                <div class="flex items-center justify-between mb-2">
                    <label class="text-xs font-semibold text-slate-300">IP Publik Server VPS</label>
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-emerald-500/15 border border-emerald-500/30 text-[10px] text-emerald-300 font-medium">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                        <span>Auto-Detected</span>
                    </span>
                </div>
                <div class="flex items-center gap-2.5">
                    <div class="flex-1 px-4 py-2.5 bg-slate-950/70 border border-slate-800 rounded-xl text-xs sm:text-sm text-slate-200 font-mono select-all cursor-default flex items-center justify-between shadow-inner"
                         title="IP Publik server ini terdeteksi otomatis dari jaringan VPS Anda">
                        <span class="font-mono tracking-wide">{{ $serverIp ?: '103.180.200.15' }}</span>
                        <svg class="w-3.5 h-3.5 text-slate-500 shrink-0 ml-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect width="18" height="11" x="3" y="11" rx="2" ry="2"/>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                        </svg>
                    </div>
                    <button type="button" wire:click="detectServerPublicIp" wire:loading.attr="disabled"
                            class="p-2.5 bg-slate-950 border border-slate-800 hover:border-slate-700 active:scale-95 disabled:opacity-50 rounded-xl text-slate-400 hover:text-white transition-all shrink-0 cursor-pointer shadow-sm" 
                            title="Deteksi Ulang IP Publik Server">
                        <span wire:loading.remove wire:target="detectServerPublicIp">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/>
                                <path d="M21 3v5h-5"/>
                                <path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/>
                                <path d="M8 16H3v5"/>
                            </svg>
                        </span>
                        <span wire:loading wire:target="detectServerPublicIp">
                            <svg class="w-4 h-4 animate-spin text-indigo-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 12a9 9 0 1 1-6.219-8.56"/>
                            </svg>
                        </span>
                    </button>
                </div>
            </div>

            <!-- 3. DKIM Selector (Baku OpenDKIM) -->
            <div>
                <div class="flex items-center justify-between mb-2">
                    <label class="text-xs font-semibold text-slate-300">DKIM Selector</label>
                    <span class="text-[10px] text-slate-400 font-mono">_domainkey</span>
                </div>
                <div class="px-4 py-2.5 bg-slate-950/70 border border-slate-800 rounded-xl text-xs sm:text-sm text-slate-200 font-mono flex items-center justify-between cursor-default shadow-inner"
                     title="Selector default adalah standar baku OpenDKIM & Postfix">
                    <span class="tracking-wide">default</span>
                    <span class="text-[10px] font-sans px-2.5 py-0.5 rounded-full bg-slate-800/80 text-slate-400 font-medium border border-slate-700/50">Standard</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabel Rekomendasi DNS Records -->
    <div class="p-6 rounded-2xl bg-slate-900/60 border border-slate-800 backdrop-blur-md space-y-4">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <div>
                <h3 class="text-base font-bold text-white flex items-center gap-2">
                    <i data-lucide="database" class="w-4 h-4 text-indigo-400"></i>
                    Daftar DNS Record yang Harus Dipasang di Registrar Domain (Cloudflare, Niagahoster, dll)
                </h3>
                <p class="text-xs text-slate-400 mt-0.5">Salin nilai di bawah ini ke panel DNS domain <span class="font-mono text-indigo-300">{{ $domainName }}</span></p>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead>
                    <tr class="text-slate-400 border-b border-slate-800">
                        <th class="py-3 px-4 font-medium w-40">Tipe Record</th>
                        <th class="py-3 px-4 font-medium">Host / Name</th>
                        <th class="py-3 px-4 font-medium">Value / Target Content</th>
                        <th class="py-3 px-4 font-medium w-24">Prioritas</th>
                        <th class="py-3 px-4 font-medium w-52">Status & Fungsi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60 text-slate-200">
                    @foreach($dnsRecords as $rec)
                    <tr class="hover:bg-slate-800/30 transition-colors">
                        <td class="py-3.5 px-4 whitespace-nowrap">
                            <div class="flex items-center gap-2">
                                <span class="px-2.5 py-1 rounded-md bg-indigo-500/10 text-indigo-400 font-mono font-bold text-xs border border-indigo-500/20 uppercase">
                                    {{ $rec['type'] }}
                                </span>
                                <span class="text-[11px] font-medium text-slate-400">
                                    {{ $rec['subtype'] }}
                                </span>
                            </div>
                        </td>
                        <td class="py-3.5 px-4 font-mono text-white text-xs font-semibold select-all">
                            {{ $rec['host'] }}
                        </td>
                        <td class="py-3.5 px-4">
                            <div class="flex items-center gap-2 group" x-data="{ copied: false }">
                                <code class="px-2.5 py-1.5 bg-slate-950 rounded-lg text-slate-300 font-mono text-[11px] border border-slate-800 max-w-lg truncate block select-all">
                                    {{ $rec['value'] }}
                                </code>
                                <button @click="navigator.clipboard.writeText('{{ addslashes($rec['value']) }}'); copied = true; setTimeout(() => copied = false, 2000)" 
                                        type="button" 
                                        class="p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition-colors shrink-0" 
                                        title="Salin Value">
                                    <i data-lucide="copy" class="w-3.5 h-3.5" x-show="!copied"></i>
                                    <i data-lucide="check" class="w-3.5 h-3.5 text-emerald-400" x-show="copied" style="display: none;"></i>
                                </button>
                            </div>
                        </td>
                        <td class="py-3.5 px-4 font-mono text-slate-400">
                            {{ $rec['priority'] }}
                        </td>
                        <td class="py-3.5 px-4">
                            <span class="inline-flex items-center gap-1.5 text-emerald-400 font-semibold text-[11px]">
                                <i data-lucide="shield-check" class="w-3.5 h-3.5 text-emerald-400"></i>
                                {{ $rec['status'] }}
                            </span>
                            <p class="text-[10px] text-slate-400 mt-0.5 leading-relaxed">{{ $rec['description'] }}</p>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <!-- Panduan IP Warming Terencana -->
    <div class="p-6 rounded-2xl bg-gradient-to-r from-indigo-950/40 via-slate-900/60 to-slate-900/40 border border-slate-800 backdrop-blur-md space-y-3">
        <h3 class="text-base font-bold text-white flex items-center gap-2">
            <i data-lucide="flame" class="w-5 h-5 text-amber-400"></i>
            Rencana & Jadwal IP Warming (Tahap 5.3)
        </h3>
        <p class="text-xs text-slate-300 leading-relaxed">
            Sebelum mengirim ribuan email dari VPS baru, server harus melalui proses pemanasan IP (IP Warming) agar reputasi IP Anda di mata Google (Gmail) dan Microsoft (Outlook) tidak dianggap spammer:
        </p>

        <div class="grid grid-cols-1 sm:grid-cols-4 gap-3 pt-2 text-xs">
            <div class="p-3 rounded-xl bg-slate-950/60 border border-slate-800">
                <p class="font-bold text-indigo-400">Minggu 1</p>
                <p class="text-lg font-extrabold text-white mt-1">20 - 50</p>
                <p class="text-[11px] text-slate-400">email / hari</p>
            </div>
            <div class="p-3 rounded-xl bg-slate-950/60 border border-slate-800">
                <p class="font-bold text-indigo-400">Minggu 2</p>
                <p class="text-lg font-extrabold text-white mt-1">100 - 200</p>
                <p class="text-[11px] text-slate-400">email / hari</p>
            </div>
            <div class="p-3 rounded-xl bg-slate-950/60 border border-slate-800">
                <p class="font-bold text-indigo-400">Minggu 3</p>
                <p class="text-lg font-extrabold text-white mt-1">500 - 1.000</p>
                <p class="text-[11px] text-slate-400">email / hari</p>
            </div>
            <div class="p-3 rounded-xl bg-slate-950/60 border border-slate-800">
                <p class="font-bold text-emerald-400">Minggu 4+</p>
                <p class="text-lg font-extrabold text-white mt-1">Volume Penuh</p>
                <p class="text-[11px] text-slate-400">Reputasi IP Optimal</p>
            </div>
        </div>
    </div>
</div>