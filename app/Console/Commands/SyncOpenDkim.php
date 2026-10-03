<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\VirtualDomain;

class SyncOpenDkim extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mail:sync-dkim {--domain= : Domain spesifik untuk dibuatkan DKIM}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Otomatisasi pembuatan file kunci DKIM dan konfigurasi OpenDKIM multi-domain untuk semua domain aktif di database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("=== Sinkronisasi OpenDKIM Multi-Domain ===");

        $specificDomain = $this->option('domain');
        if ($specificDomain) {
            $domains = VirtualDomain::where('name', $specificDomain)->get();
        } else {
            $domains = VirtualDomain::where('is_active', true)->get();
        }

        if ($domains->isEmpty()) {
            $this->warn("Tidak ada domain aktif yang ditemukan di database.");
            return 0;
        }

        $openDkimBaseDir = '/etc/opendkim';
        $keysDir = "{$openDkimBaseDir}/keys";

        if (PHP_OS_FAMILY === 'Linux') {
            @mkdir($keysDir, 0755, true);
        }

        $signingEntries = [];
        $keyEntries = [];
        $trustedHosts = [
            '127.0.0.1',
            'localhost',
            '::1',
        ];

        // Tambahkan hostname server ke TrustedHosts
        $hostname = gethostname();
        if ($hostname) {
            $trustedHosts[] = $hostname;
        }

        // Tambahkan IP publik jika ada
        $ip = @file_get_contents('https://api.ipify.org');
        if ($ip && filter_var(trim($ip), FILTER_VALIDATE_IP)) {
            $trustedHosts[] = trim($ip);
        }

        foreach ($domains as $domain) {
            $dName = strtolower(trim($domain->name));
            $this->info("Memproses domain: {$dName}...");

            $dKeyDir = "{$keysDir}/{$dName}";
            $privKeyFile = "{$dKeyDir}/default.private";
            $txtKeyFile = "{$dKeyDir}/default.txt";

            if (PHP_OS_FAMILY === 'Linux') {
                @mkdir($dKeyDir, 0750, true);

                // 1. Generate key jika belum ada
                if (!file_exists($privKeyFile)) {
                    $this->line(" - Membuat kunci RSA 2048-bit untuk {$dName}...");
                    $cmd = "opendkim-genkey -b 2048 -d " . escapeshellarg($dName) . " -D " . escapeshellarg($dKeyDir) . " -s default";
                    exec($cmd, $out, $ret);
                    if ($ret !== 0) {
                        $this->error(" - Gagal generate opendkim-genkey: " . implode("\n", $out));
                    }
                }

                if (file_exists($privKeyFile)) {
                    @chmod($privKeyFile, 0600);
                    @exec("chown -R opendkim:opendkim " . escapeshellarg($dKeyDir));
                }
            }

            // Data untuk SigningTable & KeyTable
            $signingEntries[] = "*@{$dName} default._domainkey.{$dName}";
            $keyEntries[] = "default._domainkey.{$dName} {$dName}:default:{$keysDir}/{$dName}/default.private";

            // Trusted hosts
            $trustedHosts[] = $dName;
            $trustedHosts[] = "*.{$dName}";
        }

        if (PHP_OS_FAMILY === 'Linux') {
            // Tulis SigningTable
            $signingFile = "{$openDkimBaseDir}/SigningTable";
            file_put_contents($signingFile, implode("\n", $signingEntries) . "\n");
            $this->info(" - Ditulis: {$signingFile}");

            // Tulis KeyTable
            $keyTableFile = "{$openDkimBaseDir}/KeyTable";
            file_put_contents($keyTableFile, implode("\n", $keyEntries) . "\n");
            $this->info(" - Ditulis: {$keyTableFile}");

            // Tulis TrustedHosts
            $trustedHosts = array_unique($trustedHosts);
            $trustedHostsFile = "{$openDkimBaseDir}/TrustedHosts";
            file_put_contents($trustedHostsFile, implode("\n", $trustedHosts) . "\n");
            $this->info(" - Ditulis: {$trustedHostsFile}");

            // Pastikan master opendkim.conf lengkap
            $this->ensureMasterConfig($openDkimBaseDir);

            // Set ownership
            @exec("chown -R opendkim:opendkim {$openDkimBaseDir}");

            // Hubungkan Postfix ke OpenDKIM milter
            @exec("postconf -e 'milter_default_action = accept'");
            @exec("postconf -e 'milter_protocol = 6'");
            @exec("postconf -e 'smtpd_milters = inet:127.0.0.1:12301'");
            @exec("postconf -e 'non_smtpd_milters = inet:127.0.0.1:12301'");

            // Reload services
            $this->line(" - Merestart OpenDKIM & Postfix...");
            @exec("systemctl restart opendkim postfix");

            $this->info("SUKSES: Seluruh domain telah disinkronkan ke OpenDKIM dan Postfix.");
        } else {
            $this->warn("Lingkungan Windows terdeteksi (Simulasi). Di Linux script akan menulis file konfigurasi secara otomatis.");
        }

        return 0;
    }

    /**
     * Memastikan file /etc/opendkim.conf terkonfigurasi dengan benar
     */
    protected function ensureMasterConfig(string $openDkimBaseDir): void
    {
        $confFile = '/etc/opendkim.conf';
        $defaultFile = '/etc/default/opendkim';

        $masterConf = <<<EOF
AutoRestart             Yes
AutoRestartRate         10/1h
UMask                   002
Syslog                  Yes
SyslogSuccess           Yes
LogWhy                  Yes
Canonicalization        relaxed/simple
Mode                    sv
SubDomains              no
OversignHeaders         From
UserID                  opendkim:opendkim
PidFile                 /run/opendkim/opendkim.pid
Socket                  inet:12301@127.0.0.1

KeyTable                {$openDkimBaseDir}/KeyTable
SigningTable            refile:{$openDkimBaseDir}/SigningTable
ExternalIgnoreList      refile:{$openDkimBaseDir}/TrustedHosts
InternalHosts           refile:{$openDkimBaseDir}/TrustedHosts
EOF;

        file_put_contents($confFile, $masterConf . "\n");

        // Pastikan /etc/default/opendkim menggunakan socket port yang sama
        @file_put_contents($defaultFile, "SOCKET=\"inet:12301@127.0.0.1\"\n");
        @mkdir('/run/opendkim', 0755, true);
        @exec('chown -R opendkim:opendkim /run/opendkim');
    }
}
