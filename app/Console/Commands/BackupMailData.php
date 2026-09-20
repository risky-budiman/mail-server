<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\VirtualDomain;
use App\Models\VirtualUser;
use App\Models\VirtualAlias;
use Illuminate\Support\Facades\File;

class BackupMailData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mail:backup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cadangkan (Backup) seluruh konfigurasi virtual mailbox, domain, dan user ke file JSON';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Memulai pencadangan data Virtual Mail Server...');

        $data = [
            'timestamp' => now()->toIso8601String(),
            'domains' => VirtualDomain::all(),
            'users' => VirtualUser::all(),
            'aliases' => VirtualAlias::all(),
        ];

        $backupDir = storage_path('app/backups');
        if (!File::exists($backupDir)) {
            File::makeDirectory($backupDir, 0755, true);
        }

        $filename = 'mail_backup_' . now()->format('Y_m_d_His') . '.json';
        $fullPath = $backupDir . '/' . $filename;

        File::put($fullPath, json_encode($data, JSON_PRETTY_PRINT));

        $this->info("Backup berhasil disimpan: {$fullPath}");
        return Command::SUCCESS;
    }
}
