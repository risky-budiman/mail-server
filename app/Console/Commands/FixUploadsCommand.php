<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class FixUploadsCommand extends Command
{
    protected $signature = 'mail:fix-uploads';
    protected $description = 'Periksa dan siapkan seluruh direktori penampung lampiran dan upload Livewire';

    public function handle(): int
    {
        $this->info('Memeriksa direktori upload dan konfigurasi storage...');

        $dirs = [
            storage_path('app/private/livewire-tmp'),
            storage_path('app/livewire-tmp'),
            storage_path('app/public/livewire-tmp'),
            storage_path('app/public/attachments'),
            storage_path('framework/cache/data'),
            storage_path('framework/sessions'),
            storage_path('framework/views'),
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
                $this->line("  [+] Membuat direktori: {$dir}");
            }
            @chmod($dir, 0777);
            if (is_writable($dir)) {
                $this->info("  [OK] Writable: {$dir}");
            } else {
                $this->warn("  [PERINGATAN] Belum writable: {$dir}");
            }
        }

        $this->info('Batas upload PHP saat ini:');
        $this->line('  - upload_max_filesize: ' . ini_get('upload_max_filesize'));
        $this->line('  - post_max_size: ' . ini_get('post_max_size'));
        $this->line('  - memory_limit: ' . ini_get('memory_limit'));

        return Command::SUCCESS;
    }
}
