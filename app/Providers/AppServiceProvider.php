<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Pastikan folder penampung upload sementara Livewire dan folder lampiran selalu siap & writable
        $uploadDirs = [
            storage_path('app/private/livewire-tmp'),
            storage_path('app/livewire-tmp'),
            storage_path('app/public/livewire-tmp'),
            storage_path('app/public/attachments'),
        ];

        foreach ($uploadDirs as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            @chmod($dir, 0777);
        }
    }
}


