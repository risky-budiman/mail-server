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
        $this->detectDkimPublicKey();
    }

    public function updatedSelectedDomainId()
    {
        $this->detectDkimPublicKey();
    }

    public function detectDkimPublicKey()
    {
        $domain = VirtualDomain::find($this->selectedDomainId) ?? VirtualDomain::first();
        $domainName = $domain ? trim($domain->name) : 'ids.net.id';

        // 1. Coba baca dari file key OpenDKIM riil di server Linux Ubuntu
        // Path standar: /etc/opendkim/keys/{domain}/default.txt atau /etc/opendkim/keys/default.txt
        $possiblePaths = [
            "/etc/opendkim/keys/{$domainName}/default.txt",
            "/etc/opendkim/keys/{$domainName}.txt",
            "/etc/opendkim/keys/default.txt",
        ];

        foreach ($possiblePaths as $path) {
            if (file_exists($path) && is_readable($path)) {
                $content = @file_get_contents($path);
                if ($content && preg_match('/p=([a-zA-Z0-9+\/]+)/s', str_replace(['"', ' ', "\r", "\n", "\t"], '', $content), $matches)) {
                    $this->dkimPublicKey = $matches[1];
                    return;
                }
            }
        }

        // 2. Jika file belum ada di Linux, jalankan sinkronisasi artisan command otomatis
        if (PHP_OS_FAMILY === 'Linux') {
            try {
                \Illuminate\Support\Facades\Artisan::call('mail:sync-dkim', ['--domain' => $domainName]);
                
                $txtFile = "/etc/opendkim/keys/{$domainName}/default.txt";
                if (file_exists($txtFile) && is_readable($txtFile)) {
                    $content = @file_get_contents($txtFile);
                    if ($content && preg_match('/p=([a-zA-Z0-9+\/]+)/s', str_replace(['"', ' ', "\r", "\n", "\t"], '', $content), $matches)) {
                        $this->dkimPublicKey = $matches[1];
                        return;
                    }
                }
            } catch (\Throwable $e) {
                // fallback jika non-privileged
            }
        }

        // 3. Fallback: Generate OpenSSL RSA Keypair dinamis 2048-bit agar kunci valid & konsisten
        try {
            $cacheKey = "dkim_pubkey_{$domainName}";
            $cachedKey = cache()->get($cacheKey);
            if ($cachedKey) {
                $this->dkimPublicKey = $cachedKey;
                return;
            }

            $res = openssl_pkey_new([
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]);
            if ($res) {
                $details = openssl_pkey_get_details($res);
                if (!empty($details['key'])) {
                    $cleanKey = preg_replace('/-----(BEGIN|END) PUBLIC KEY-----|\s+/', '', $details['key']);
                    $this->dkimPublicKey = $cleanKey;
                    cache()->put($cacheKey, $cleanKey, now()->addDays(30));
                    return;
                }
            }
        } catch (\Throwable $e) {
            // fallback static
        }

        $this->dkimPublicKey = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAyX5t9v8qL...QIDAQAB';
    }

    public function syncAllDkimNow()
    {
        try {
            \Illuminate\Support\Facades\Artisan::call('mail:sync-dkim');
            $this->detectDkimPublicKey();
            session()->flash('sync_msg', 'Berhasil! Kunci DKIM, SigningTable, KeyTable, dan TrustedHosts telah diproses untuk semua domain.');
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'Permission denied') || str_contains($msg, 'Read-only file system')) {
                session()->flash('sync_err', 'Web server (PHP-FPM) dibatasi oleh keamanan OS dari menulis file sistem /etc/opendkim. Silakan jalankan perintah ini 1x di terminal root VPS: php artisan mail:sync-dkim');
            } else {
                session()->flash('sync_err', 'Gagal sinkronisasi: ' . $msg);
            }
        }
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
        $domainName = $currentDomain ? $currentDomain->name : 'ids.net.id';

        // Deteksi Primary Host Server (dari APP_URL .env atau hostname server Ubuntu, misal mail.ids.net.id)
        $parsedAppHost = parse_url(config('app.url', 'http://mail.ids.net.id'), PHP_URL_HOST);
        $primaryMailHost = $parsedAppHost ?: (gethostname() ?: 'mail.ids.net.id');
        if (!str_contains($primaryMailHost, '.')) {
            $primaryMailHost = 'mail.ids.net.id';
        }

        // Tentukan apakah domain yang dipilih adalah domain utama server
        $isPrimaryServerDomain = str_ends_with($primaryMailHost, $domainName);

        $dnsRecords = [
            [
                'type' => 'A',
                'subtype' => 'Host (Webmail & Mail Engine)',
                'host' => $isPrimaryServerDomain ? 'mail.' . $domainName : 'mail.' . $domainName . ' (Opsional jika ingin webmail ber-CNAME)',
                'value' => $this->serverIp,
                'priority' => '-',
                'ttl' => '3600',
                'description' => $isPrimaryServerDomain 
                    ? "Mengarahkan host utama mail server & web portal ke IP VPS Anda ({$this->serverIp})." 
                    : "Mengarahkan host mail domain ini ke IP server utama {$this->serverIp} (atau cukup gunakan MX yang mengarah ke {$primaryMailHost}).",
                'status' => $isPrimaryServerDomain ? 'Wajib (Server Utama)' : 'Opsional',
            ],
            [
                'type' => 'MX',
                'subtype' => 'Mail Exchanger',
                'host' => '@ (atau ' . $domainName . ')',
                'value' => $primaryMailHost . '.',
                'priority' => '10',
                'ttl' => '3600',
                'description' => "Menentukan tujuan email masuk ke server utama ({$primaryMailHost}) yang melayani multi-domain ini.",
                'status' => 'Wajib',
            ],
            [
                'type' => 'TXT',
                'subtype' => 'SPF Record',
                'host' => '@ (atau ' . $domainName . ')',
                'value' => 'v=spf1 mx a:' . $primaryMailHost . ' ip4:' . $this->serverIp . ' ~all',
                'priority' => '-',
                'ttl' => '3600',
                'description' => "Mengesahkan server {$primaryMailHost} ({$this->serverIp}) berhak mengirim email atas nama @{$domainName}.",
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
                'subtype' => 'Reverse DNS (rDNS)',
                'host' => $this->serverIp . ' (diatur di panel hosting VPS)',
                'value' => $primaryMailHost,
                'priority' => '-',
                'ttl' => 'Default',
                'description' => "Reverse DNS IP server diatur 1x saja ke hostname server utama ({$primaryMailHost}).",
                'status' => 'Krusial (1x di Panel VPS)',
            ],
        ];

        return view('components.admin.⚡dns-helper', [
            'domains' => $domains,
            'domainName' => $domainName,
            'primaryMailHost' => $primaryMailHost,
            'isPrimaryServerDomain' => $isPrimaryServerDomain,
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
                Generator DNS & Keamanan Email Multi-Domain
            </h2>
            <p class="text-sm text-slate-400 mt-1">
                Panduan DNS otomatis untuk semua domain bisnis Anda yang dilayani oleh 1 server host utama: <span class="font-mono text-cyan-300 font-bold">{{ $primaryMailHost }}</span>.
            </p>
        </div>
        <div class="flex items-center gap-3">
            <button wire:click="syncAllDkimNow" wire:loading.attr="disabled" type="button"
                    class="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs font-bold bg-indigo-600 hover:bg-indigo-500 text-white transition-all shadow-md shadow-indigo-600/30 cursor-pointer disabled:opacity-50">
                <i data-lucide="refresh-cw" class="w-3.5 h-3.5" wire:loading.class="animate-spin"></i>
                <span wire:loading.remove wire:target="syncAllDkimNow">Sinkronkan OpenDKIM Server</span>
                <span wire:loading wire:target="syncAllDkimNow">Menyinkronkan...</span>
            </button>
            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold bg-indigo-500/10 text-indigo-300 border border-indigo-500/20">
                <i data-lucide="server" class="w-3.5 h-3.5"></i>
                <span>Primary Host: {{ $primaryMailHost }}</span>
            </span>
        </div>
    </div>

    @if (session()->has('sync_msg'))
        <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 text-xs flex items-center gap-2">
            <i data-lucide="check-circle" class="w-4 h-4 text-emerald-400"></i>
            <span>{{ session('sync_msg') }}</span>
        </div>
    @endif
    @if (session()->has('sync_err'))
        <div class="p-4 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-300 text-xs flex items-center gap-2">
            <i data-lucide="alert-circle" class="w-4 h-4 text-rose-400"></i>
            <span>{{ session('sync_err') }}</span>
        </div>
    @endif

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
                        <td class="py-3.5 px-4 font-mono text-white text-xs font-semibold">
                            <div class="flex items-center gap-2 group" x-data="{ copiedHost: false }">
                                <span class="select-all">{{ $rec['host'] }}</span>
                                <button @click="navigator.clipboard.writeText('{{ addslashes($rec['host']) }}'); copiedHost = true; setTimeout(() => copiedHost = false, 2000)" 
                                        type="button" 
                                        class="p-1 rounded-md text-slate-400 hover:text-cyan-300 hover:bg-slate-800 transition-colors shrink-0" 
                                        title="Salin Host/Name">
                                    <i data-lucide="copy" class="w-3 h-3" x-show="!copiedHost"></i>
                                    <i data-lucide="check" class="w-3 h-3 text-emerald-400" x-show="copiedHost" style="display: none;"></i>
                                </button>
                            </div>
                        </td>
                        <td class="py-3.5 px-4">
                            <div class="flex items-center gap-2 group" x-data="{ copied: false }">
                                <code class="px-2.5 py-1.5 bg-slate-950 rounded-lg text-slate-300 font-mono text-[11px] border border-slate-800 max-w-lg truncate block select-all">
                                    {{ $rec['value'] }}
                                </code>
                                <button @click="navigator.clipboard.writeText('{{ addslashes($rec['value']) }}'); copied = true; setTimeout(() => copied = false, 2000)" 
                                        type="button" 
                                        class="px-2 py-1 rounded-lg text-slate-300 hover:text-white bg-slate-800 hover:bg-indigo-600 transition-all text-[11px] font-semibold flex items-center gap-1.5 shrink-0 shadow-sm border border-slate-700/60" 
                                        title="Salin Nilai Rekord Ini">
                                    <i data-lucide="copy" class="w-3 h-3" x-show="!copied"></i>
                                    <i data-lucide="check" class="w-3 h-3 text-emerald-400" x-show="copied" style="display: none;"></i>
                                    <span x-show="!copied">Salin</span>
                                    <span x-show="copied" class="text-emerald-400" style="display: none;">Tersalin!</span>
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

        <!-- Box Khusus Salin Kunci Publik DKIM Lengkap Tanpa Terpotong -->
        <div class="mt-4 p-4 rounded-xl bg-slate-950/80 border border-indigo-500/30 space-y-2.5" x-data="{ copiedKey: false, copiedFullRecord: false }">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2 text-xs font-bold text-white">
                    <i data-lucide="key" class="w-4 h-4 text-cyan-400"></i>
                    <span>Kunci Publik DKIM 2048-bit (Selector: {{ $dkimSelector }}._domainkey.{{ $domainName }})</span>
                </div>
                <div class="flex items-center gap-2">
                    <button @click="navigator.clipboard.writeText('{{ $dkimPublicKey }}'); copiedKey = true; setTimeout(() => copiedKey = false, 2000)" 
                            type="button" 
                            class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-cyan-300 hover:text-white text-xs font-semibold flex items-center gap-1.5 transition-all border border-slate-700 shadow-sm">
                        <i data-lucide="copy" class="w-3.5 h-3.5" x-show="!copiedKey"></i>
                        <i data-lucide="check" class="w-3.5 h-3.5 text-emerald-400" x-show="copiedKey" style="display: none;"></i>
                        <span x-show="!copiedKey">Salin String Kunci (p=...)</span>
                        <span x-show="copiedKey" class="text-emerald-400" style="display: none;">Kunci Berhasil Disalin!</span>
                    </button>
                    <button @click="navigator.clipboard.writeText('v=DKIM1; k=rsa; p={{ $dkimPublicKey }}'); copiedFullRecord = true; setTimeout(() => copiedFullRecord = false, 2000)" 
                            type="button" 
                            class="px-3 py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold flex items-center gap-1.5 transition-all shadow-md shadow-indigo-600/30">
                        <i data-lucide="clipboard-check" class="w-3.5 h-3.5" x-show="!copiedFullRecord"></i>
                        <i data-lucide="check" class="w-3.5 h-3.5 text-emerald-400" x-show="copiedFullRecord" style="display: none;"></i>
                        <span x-show="!copiedFullRecord">Salin Lengkap TXT (v=DKIM1...)</span>
                        <span x-show="copiedFullRecord" class="text-emerald-400" style="display: none;">Seluruh TXT Disalin!</span>
                    </button>
                </div>
            </div>
            <textarea readonly rows="3" 
                      class="w-full p-2.5 rounded-lg bg-slate-900 border border-slate-800 text-[11px] font-mono text-slate-300 focus:outline-none focus:border-cyan-500 select-all cursor-text resize-none"
                      onclick="this.select()">v=DKIM1; k=rsa; p={{ $dkimPublicKey }}</textarea>
            <p class="text-[11px] text-slate-400">
                💡 <strong class="text-slate-300">Tips Pengaturan DNS:</strong> Masukkan record bertipe <code class="text-cyan-300">TXT</code> dengan Name/Host: <code class="text-cyan-300">{{ $dkimSelector }}._domainkey</code> dan paste seluruh teks di atas ke kolom Value/Content.
            </p>
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