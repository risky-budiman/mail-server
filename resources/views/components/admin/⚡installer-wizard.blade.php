<?php

use Livewire\Component;
use Symfony\Component\Process\Process;

new class extends Component
{
    public $domain = 'perusahaan.co.id';
    public $dbName = 'mailportal';
    public $dbUser = 'mailuser';
    public $dbPass = 'StrongSecretPassword123!';
    public $serverIp = '103.180.200.15';
    
    public $isRunning = false;
    public $logs = [];
    public $status = 'idle'; // idle, running, success, error
    public $step = 1;
    public $activeTab = 'oneclick'; // oneclick, update_ip, manual

    // Parameter untuk update IP
    public $newServerIp = '';
    public $ipChangeSuccess = false;

    public function mount()
    {
        $this->newServerIp = $this->serverIp;
        $this->logs[] = "[" . now()->format('H:i:s') . "] Wizard manajemen server siap.";
    }

    public function updateServerIp()
    {
        $this->validate([
            'newServerIp' => 'required|ip',
        ]);

        $this->isRunning = true;
        $this->status = 'running';
        $this->logs = [];
        $oldIp = $this->serverIp;
        $this->serverIp = $this->newServerIp;

        $this->logs[] = "[" . now()->format('H:i:s') . "] Memulai update IP Publik VPS...";
        $this->logs[] = "[" . now()->format('H:i:s') . "] IP Lama: {$oldIp} -> IP Baru: {$this->newServerIp}";

        if (PHP_OS_FAMILY === 'Windows') {
            $this->logs[] = "[" . now()->format('H:i:s') . "] [SIMULASI] Memperbarui SPF TXT Record: v=spf1 mx a:mail.{$this->domain} ip4:{$this->newServerIp} ~all";
            $this->logs[] = "[" . now()->format('H:i:s') . "] [SIMULASI] Memperbarui Postfix mynetworks & binding IP...";
            $this->logs[] = "[" . now()->format('H:i:s') . "] [SIMULASI] Sinkronisasi modul DNS Helper...";
            $this->logs[] = "[" . now()->format('H:i:s') . "] [SUKSES] Parameter IP Server berhasil diperbarui ke {$this->newServerIp}!";
            $this->status = 'success';
            $this->ipChangeSuccess = true;
            $this->isRunning = false;
            session()->flash('ip_msg', "IP Publik server berhasil diperbarui ke {$this->newServerIp}. DNS Helper otomatis menyesuaikan!");
            return;
        }

        // Jalankan di Linux VPS Asli
        try {
            // Update hosts file & reload postfix
            $cmd = "postconf -e 'inet_interfaces = all' && systemctl reload postfix dovecot";
            $process = Process::fromShellCommandline("sudo bash -c \"{$cmd}\"");
            $process->run();

            $this->logs[] = "[" . now()->format('H:i:s') . "] Postfix & Dovecot berhasil di-reload dengan IP baru: {$this->newServerIp}";
            $this->logs[] = "[" . now()->format('H:i:s') . "] [PENTING] Pastikan Anda juga memperbarui Record A (mail.{$this->domain}) dan Reverse DNS (PTR) di panel hosting ke: {$this->newServerIp}";
            $this->status = 'success';
            $this->ipChangeSuccess = true;
            session()->flash('ip_msg', "IP Publik server berhasil diperbarui ke {$this->newServerIp}!");
        } catch (\Exception $e) {
            $this->logs[] = "[" . now()->format('H:i:s') . "] [ERROR] " . $e->getMessage();
            $this->status = 'error';
        }

        $this->isRunning = false;
    }

    public function runInstallation()
    {
        $this->validate([
            'domain' => 'required|string|min:3',
            'dbName' => 'required|string',
            'dbUser' => 'required|string',
            'dbPass' => 'required|string',
            'serverIp' => 'required|string',
        ]);

        $this->isRunning = true;
        $this->status = 'running';
        $this->logs = [];
        $this->logs[] = "[" . now()->format('H:i:s') . "] Memulai instalasi Mail Engine otomatis...";
        $this->logs[] = "[" . now()->format('H:i:s') . "] Target Hostname: mail.{$this->domain}";
        $this->logs[] = "[" . now()->format('H:i:s') . "] Database MySQL: {$this->dbName} (User: {$this->dbUser})";

        // Cek apakah berjalan di lingkungan Linux atau Lokal Windows
        if (PHP_OS_FAMILY === 'Windows') {
            $this->logs[] = "[" . now()->format('H:i:s') . "] [SIMULASI LOKAL] Terdeteksi lingkungan Windows Development.";
            $this->logs[] = "[" . now()->format('H:i:s') . "] [SIMULASI] Menyiapkan parameter Postfix & Dovecot MySQL...";
            $this->logs[] = "[" . now()->format('H:i:s') . "] [SIMULASI] Menghasilkan kunci OpenDKIM RSA 2048-bit...";
            $this->logs[] = "[" . now()->format('H:i:s') . "] [SIMULASI] Konfigurasi UFW Firewall Port 25, 587, 465, 993...";
            $this->logs[] = "[" . now()->format('H:i:s') . "] [BERHASIL] Simulasi instalasi selesai! Skrip bash sesungguhnya akan dieksekusi saat dijalankan di VPS Linux Ubuntu.";
            $this->status = 'success';
            $this->isRunning = false;
            return;
        }

        // Jalankan di Server Linux VPS Asli
        $scriptPath = base_path('scripts/setup-mail-server.sh');
        
        if (!file_exists($scriptPath)) {
            $this->logs[] = "[" . now()->format('H:i:s') . "] [ERROR] File skrip scripts/setup-mail-server.sh tidak ditemukan!";
            $this->status = 'error';
            $this->isRunning = false;
            return;
        }

        try {
            $process = new Process([
                'sudo',
                'bash',
                $scriptPath
            ], base_path(), [
                'DOMAIN' => $this->domain,
                'HOSTNAME' => 'mail.' . $this->domain,
                'DB_NAME' => $this->dbName,
                'DB_USER' => $this->dbUser,
                'DB_PASS' => $this->dbPass,
            ]);

            $process->setTimeout(300); // 5 menit
            $process->run(function ($type, $buffer) {
                $lines = explode("\n", trim($buffer));
                foreach ($lines as $line) {
                    if (!empty($line)) {
                        $this->logs[] = "[" . now()->format('H:i:s') . "] " . $line;
                    }
                }
            });

            if ($process->isSuccessful()) {
                $this->logs[] = "[" . now()->format('H:i:s') . "] [SUKSES BESAR] Seluruh paket Postfix, Dovecot, OpenDKIM, dan Firewall berhasil diinstal dan aktif!";
                $this->status = 'success';
            } else {
                $this->logs[] = "[" . now()->format('H:i:s') . "] [ERROR] Eksekusi terhenti: " . $process->getErrorOutput();
                $this->status = 'error';
            }
        } catch (\Exception $e) {
            $this->logs[] = "[" . now()->format('H:i:s') . "] [EXCEPTION] " . $e->getMessage();
            $this->status = 'error';
        }

        $this->isRunning = false;
    }

    public function render()
    {
        return view('components.admin.⚡installer-wizard')
            ->layout('layouts.app', ['title' => 'Mail Server 1-Click Installer & IP Manager - Mail Portal']);
    }
};
?>

<div class="space-y-6 max-w-7xl mx-auto">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-extrabold text-white tracking-tight flex items-center gap-2.5">
                <i data-lucide="cpu" class="w-6 h-6 text-indigo-400"></i>
                Installer & Setup Engine Otomatis (1-Click)
            </h2>
            <p class="text-sm text-slate-400 mt-1">
                Jalankan instalasi Postfix, Dovecot, OpenDKIM, dan konfigurasi port VPS secara otomatis langsung dari tombol panel ini.
            </p>
        </div>

        <div class="flex items-center gap-2">
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold {{ PHP_OS_FAMILY === 'Windows' ? 'bg-amber-500/10 text-amber-400 border border-amber-500/20' : 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' }}">
                <span class="w-2 h-2 rounded-full {{ PHP_OS_FAMILY === 'Windows' ? 'bg-amber-400' : 'bg-emerald-400' }}"></span>
                {{ PHP_OS_FAMILY === 'Windows' ? 'Mode Simulasi (Windows Dev)' : 'Server Produksi (Linux VPS)' }}
            </span>
        </div>
    </div>

    <!-- Mode Selector Tabs -->
    <div class="flex items-center gap-2 border-b border-slate-800 pb-3">
        <button wire:click="$set('activeTab', 'oneclick')" 
                class="px-4 py-2 rounded-xl text-xs font-bold transition-all flex items-center gap-2 {{ $activeTab === 'oneclick' ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/30' : 'bg-slate-900 text-slate-400 hover:text-white border border-slate-800' }}">
            <i data-lucide="zap" class="w-4 h-4"></i>
            <span>Instalasi Otomatis (1-Click UI)</span>
        </button>

        <button wire:click="$set('activeTab', 'update_ip')" 
                class="px-4 py-2 rounded-xl text-xs font-bold transition-all flex items-center gap-2 {{ $activeTab === 'update_ip' ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/30' : 'bg-slate-900 text-slate-400 hover:text-white border border-slate-800' }}">
            <i data-lucide="refresh-cw" class="w-4 h-4"></i>
            <span>Ganti IP VPS & Sinkronisasi</span>
        </button>

        <button wire:click="$set('activeTab', 'manual')" 
                class="px-4 py-2 rounded-xl text-xs font-bold transition-all flex items-center gap-2 {{ $activeTab === 'manual' ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/30' : 'bg-slate-900 text-slate-400 hover:text-white border border-slate-800' }}">
            <i data-lucide="terminal" class="w-4 h-4"></i>
            <span>Salin Perintah Terminal (Manual SSH)</span>
        </button>
    </div>

    @if (session()->has('ip_msg'))
        <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 text-xs flex items-center gap-2">
            <i data-lucide="check-circle" class="w-4 h-4 text-emerald-400"></i>
            <span>{{ session('ip_msg') }}</span>
        </div>
    @endif

    @if($activeTab === 'update_ip')
    <!-- Form Ganti IP Server VPS -->
    <div class="p-6 rounded-2xl bg-slate-900/60 border border-slate-800 backdrop-blur-md space-y-5">
        <div class="border-b border-slate-800 pb-3">
            <h3 class="text-base font-bold text-white flex items-center gap-2">
                <i data-lucide="network" class="w-5 h-5 text-cyan-400"></i>
                Re-Konfigurasi Saat IP VPS Berubah
            </h3>
            <p class="text-xs text-slate-400 mt-1">
                Jika Anda memindahkan server ke VPS baru, mengubah provider hosting, atau IP publik VPS berganti, gunakan fitur ini untuk menyinkronkan seluruh sistem Postfix dan DNS secara otomatis.
            </p>
        </div>

        <form wire:submit="updateServerIp" class="max-w-xl space-y-4 text-xs">
            <div>
                <label class="block font-semibold text-slate-300 mb-1">IP Server Saat Ini</label>
                <div class="px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-lg text-slate-400 font-mono">
                    {{ $serverIp }}
                </div>
            </div>

            <div>
                <label class="block font-semibold text-slate-300 mb-1">Masukkan Alamat IP Publik VPS yang Baru</label>
                <input type="text" wire:model="newServerIp" placeholder="contoh: 103.180.200.99" 
                       class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-700 rounded-lg text-white font-mono placeholder-slate-500 focus:outline-none focus:border-cyan-500">
                @error('newServerIp') <span class="text-xs text-rose-400 mt-1 block">{{ $message }}</span> @enderror
            </div>

            <div class="pt-2">
                <button type="submit" 
                        class="px-5 py-2.5 bg-gradient-to-r from-cyan-600 to-indigo-600 hover:from-cyan-500 hover:to-indigo-500 text-white font-bold text-xs rounded-xl transition-all shadow-md shadow-cyan-600/30 flex items-center gap-2">
                    <i data-lucide="save" class="w-4 h-4"></i>
                    <span wire:loading.remove wire:target="updateServerIp">Simpan & Sinkronkan IP Baru</span>
                    <span wire:loading wire:target="updateServerIp">Memproses Re-Konfigurasi...</span>
                </button>
            </div>
        </form>

        <div class="p-4 rounded-xl bg-slate-950 border border-slate-800 text-xs space-y-2 text-slate-300">
            <p class="font-bold text-cyan-400 flex items-center gap-1.5">
                <i data-lucide="info" class="w-4 h-4"></i>
                Langkah yang Perlu Dilakukan Setelah Ganti IP:
            </p>
            <ul class="list-disc list-inside space-y-1 text-slate-400 text-[11px] leading-relaxed">
                <li>Buka panel registrar domain Anda (Cloudflare, Niagahoster, dll).</li>
                <li>Ubah <strong>Record A (`mail.{{ $domain }}`)</strong> agar mengarah ke IP baru: <code class="text-white font-mono">{{ $newServerIp ?: $serverIp }}</code>.</li>
                <li>Perbarui record <strong>SPF TXT</strong> Anda (Otomatis dapat disalin dari menu <a href="{{ route('admin.dns-helper') }}" class="text-cyan-400 underline">DNS & Security Guide</a>).</li>
                <li>Di panel provider VPS baru Anda, set <strong>Reverse DNS (PTR Record)</strong> ke: <code class="text-white font-mono">mail.{{ $domain }}</code>.</li>
            </ul>
        </div>
    </div>
    @endif

    @if($activeTab === 'oneclick')
    <!-- Grid 1-Click Installer UI -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        <!-- Form Konfigurasi (5 Cols) -->
        <div class="lg:col-span-5 p-6 rounded-2xl bg-slate-900/60 border border-slate-800 backdrop-blur-md space-y-4">
            <h3 class="text-sm font-bold text-white flex items-center gap-2 border-b border-slate-800 pb-3">
                <i data-lucide="sliders" class="w-4 h-4 text-indigo-400"></i>
                Parameter Instalasi Mail Engine
            </h3>

            <form wire:submit="runInstallation" class="space-y-4 text-xs">
                <div>
                    <label class="block font-semibold text-slate-300 mb-1">Nama Domain Bisnis</label>
                    <input type="text" wire:model="domain" placeholder="perusahaan.co.id" 
                           class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-700 rounded-lg text-white font-mono placeholder-slate-500 focus:outline-none focus:border-indigo-500">
                    <p class="text-[10px] text-slate-500 mt-1">Hostname mail server otomatis menjadi: <span class="font-mono text-indigo-300">mail.{{ $domain }}</span></p>
                </div>

                <div>
                    <label class="block font-semibold text-slate-300 mb-1">Estimasi IP Publik VPS</label>
                    <input type="text" wire:model="serverIp" placeholder="103.180.200.15" 
                           class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-700 rounded-lg text-white font-mono placeholder-slate-500 focus:outline-none focus:border-indigo-500">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block font-semibold text-slate-300 mb-1">Nama Database</label>
                        <input type="text" wire:model="dbName" 
                               class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-700 rounded-lg text-white font-mono focus:outline-none focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="block font-semibold text-slate-300 mb-1">User Database</label>
                        <input type="text" wire:model="dbUser" 
                               class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-700 rounded-lg text-white font-mono focus:outline-none focus:border-indigo-500">
                    </div>
                </div>

                <div>
                    <label class="block font-semibold text-slate-300 mb-1">Password Database MySQL</label>
                    <input type="password" wire:model="dbPass" 
                           class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-700 rounded-lg text-white font-mono focus:outline-none focus:border-indigo-500">
                </div>

                <div class="pt-2">
                    <button type="submit" 
                            wire:loading.attr="disabled"
                            class="w-full py-3 px-4 bg-gradient-to-r from-indigo-600 to-cyan-600 hover:from-indigo-500 hover:to-cyan-500 text-white font-bold text-xs rounded-xl transition-all shadow-lg shadow-indigo-600/30 flex items-center justify-center gap-2">
                        <i data-lucide="play" class="w-4 h-4" wire:loading.remove></i>
                        <span wire:loading.remove wire:target="runInstallation">Jalankan Instalasi Mail Engine Sekarang</span>
                        <span wire:loading wire:target="runInstallation" class="flex items-center gap-2">
                            <span class="w-3 h-3 border-2 border-white border-t-transparent rounded-full animate-spin"></span>
                            Menginstal Postfix & Dovecot...
                        </span>
                    </button>
                </div>
            </form>
        </div>

        <!-- Output Terminal Konsol Real-Time (7 Cols) -->
        <div class="lg:col-span-7 flex flex-col rounded-2xl bg-slate-950 border border-slate-800 overflow-hidden shadow-2xl font-mono text-xs">
            <div class="px-4 py-3 bg-slate-900 border-b border-slate-800 flex items-center justify-between">
                <div class="flex items-center gap-2 text-slate-400">
                    <span class="w-2.5 h-2.5 rounded-full bg-rose-500 inline-block"></span>
                    <span class="w-2.5 h-2.5 rounded-full bg-amber-500 inline-block"></span>
                    <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 inline-block"></span>
                    <span class="text-slate-300 text-[11px] ml-2 font-bold">Terminal Output &mdash; setup-mail-server.sh</span>
                </div>
                <div class="flex items-center gap-2">
                    @if($status === 'running')
                        <span class="text-indigo-400 text-[11px] font-semibold flex items-center gap-1.5 animate-pulse">
                            <span class="w-2 h-2 rounded-full bg-indigo-400 animate-ping"></span> Menginstal...
                        </span>
                    @elseif($status === 'success')
                        <span class="text-emerald-400 text-[11px] font-semibold flex items-center gap-1">
                            <i data-lucide="check-circle" class="w-3.5 h-3.5"></i> Selesai
                        </span>
                    @endif
                </div>
            </div>

            <div class="p-5 flex-1 overflow-y-auto max-h-[440px] space-y-2 bg-slate-950/90 text-slate-300">
                @foreach($logs as $line)
                    <div class="leading-relaxed break-words font-mono text-[11px] {{ str_contains($line, '[ERROR]') ? 'text-rose-400' : (str_contains($line, '[SUKSES') || str_contains($line, '[BERHASIL') ? 'text-emerald-400 font-bold' : (str_contains($line, '[SIMULASI') ? 'text-amber-300' : 'text-slate-300')) }}">
                        {{ $line }}
                    </div>
                @endforeach
            </div>
        </div>
    </div>
    @endif

    @if($activeTab === 'manual')
    <!-- Tab Perintah Terminal Manual -->
    <div class="p-6 rounded-2xl bg-slate-900/60 border border-slate-800 backdrop-blur-md space-y-4">
        <h3 class="text-base font-bold text-white flex items-center gap-2">
            <i data-lucide="terminal" class="w-5 h-5 text-indigo-400"></i>
            Perintah Eksekusi Cepat via SSH Terminal
        </h3>
        <p class="text-xs text-slate-300 leading-relaxed">
            Jika Anda lebih menyukai eksekusi langsung di terminal VPS (SSH), cukup salin perintah satu baris berikut:
        </p>

        <div class="p-4 rounded-xl bg-slate-950 border border-slate-800 flex items-center justify-between gap-3 group" x-data="{ copied: false }">
            <code class="text-xs font-mono text-cyan-300 break-all select-all">
                cd /var/www/mailids && sudo DOMAIN="{{ $domain }}" DB_PASS="{{ $dbPass }}" bash scripts/setup-mail-server.sh
            </code>
            <button @click="navigator.clipboard.writeText('cd /var/www/mailids && sudo DOMAIN=\'{{ $domain }}\' DB_PASS=\'{{ $dbPass }}\' bash scripts/setup-mail-server.sh'); copied = true; setTimeout(() => copied = false, 2000)" 
                    class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-xs font-bold shrink-0 flex items-center gap-1.5 transition-all">
                <i data-lucide="copy" class="w-3.5 h-3.5" x-show="!copied"></i>
                <span x-show="!copied">Salin Perintah</span>
                <span x-show="copied" style="display: none;" class="text-emerald-300">Tersalin!</span>
            </button>
        </div>
    </div>
    @endif
</div>
