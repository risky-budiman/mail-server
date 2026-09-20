<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VirtualUser extends Authenticatable
{
    use Notifiable;

    protected $table = 'virtual_users';

    protected $fillable = [
        'domain_id',
        'email',
        'name',
        'password',
        'quota_bytes',
        'used_bytes',
        'maildir_path',
        'is_active',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'quota_bytes' => 'integer',
        'used_bytes' => 'integer',
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
    ];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(VirtualDomain::class, 'domain_id');
    }

    public function getFormattedQuotaAttribute(): string
    {
        $gb = $this->quota_bytes / (1024 * 1024 * 1024);
        if ($gb >= 1) {
            return round($gb, 1) . ' GB';
        }
        return round($this->quota_bytes / (1024 * 1024), 0) . ' MB';
    }

    public function getFormattedUsedAttribute(): string
    {
        $gb = $this->used_bytes / (1024 * 1024 * 1024);
        if ($gb >= 1) {
            return round($gb, 2) . ' GB';
        }
        $mb = $this->used_bytes / (1024 * 1024);
        if ($mb >= 1) {
            return round($mb, 1) . ' MB';
        }
        $kb = $this->used_bytes / 1024;
        return round($kb, 1) . ' KB';
    }

    public function getQuotaUsagePercentAttribute(): int
    {
        if ($this->quota_bytes == 0) return 0;
        return min(100, (int) round(($this->used_bytes / $this->quota_bytes) * 100));
    }

    /**
     * Sinkronisasi ukuran fisik Maildir di server produksi (Linux /var/vmail)
     * atau perbarui dari data ukuran email yang terhitung.
     */
    public function syncMaildirDiskUsage(?int $calculatedBytes = null): void
    {
        if ($calculatedBytes !== null) {
            $this->update(['used_bytes' => $calculatedBytes]);
            return;
        }

        $fullPath = '/var/vmail/' . ltrim($this->maildir_path, '/');
        if (is_dir($fullPath)) {
            // Jalankan kalkulasi ukuran direktori fisik Maildir di Linux
            $bytes = 0;
            try {
                $output = @shell_exec('du -sb ' . escapeshellarg($fullPath));
                if ($output && preg_match('/^(\d+)/', trim($output), $matches)) {
                    $bytes = (int) $matches[1];
                }
            } catch (\Throwable $e) {
                // Fallback jika shell_exec tidak tersedia
            }

            if ($bytes > 0) {
                $this->update(['used_bytes' => $bytes]);
            }
        }
    }
}
