<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\VirtualDomain;
use App\Models\VirtualUser;
use App\Models\VirtualAlias;
use Illuminate\Support\Facades\Hash;

class VirtualMailSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Domain Contoh
        $domain = VirtualDomain::updateOrCreate(
            ['name' => 'perusahaan.net.id'],
            [
                'description' => 'Domain Utama Bisnis',
                'is_active' => true,
            ]
        );

        $domainSecond = VirtualDomain::updateOrCreate(
            ['name' => 'techflow.id'],
            [
                'description' => 'Layanan Software & Cloud',
                'is_active' => true,
            ]
        );

        // 2. Akun-akun Virtual User (Dovecot compatible hash)
        // Catatan: Hash bcrypt standard Laravel kompatibel dengan dovecot passdb BLF-CRYPT/BCRYPT
        $passwordHash = Hash::make('Secret123!');

        VirtualUser::updateOrCreate(
            ['email' => 'admin@perusahaan.net.id'],
            [
                'domain_id' => $domain->id,
                'name' => 'Administrator Utama',
                'password' => $passwordHash,
                'quota_bytes' => 5368709120, // 5 GB
                'used_bytes' => 1247805440,  // 1.16 GB
                'maildir_path' => 'perusahaan.net.id/admin/',
                'is_active' => true,
                'last_login_at' => now()->subMinutes(12),
            ]
        );

        VirtualUser::updateOrCreate(
            ['email' => 'budi.santoso@perusahaan.net.id'],
            [
                'domain_id' => $domain->id,
                'name' => 'Budi Santoso',
                'password' => $passwordHash,
                'quota_bytes' => 2147483648, // 2 GB
                'used_bytes' => 450971520,   // ~430 MB
                'maildir_path' => 'perusahaan.net.id/budi.santoso/',
                'is_active' => true,
                'last_login_at' => now()->subHours(3),
            ]
        );

        VirtualUser::updateOrCreate(
            ['email' => 'siti.aminah@perusahaan.net.id'],
            [
                'domain_id' => $domain->id,
                'name' => 'Siti Aminah (Finance)',
                'password' => $passwordHash,
                'quota_bytes' => 2147483648,
                'used_bytes' => 1890000000,  // ~1.76 GB (Hampir penuh)
                'maildir_path' => 'perusahaan.net.id/siti.aminah/',
                'is_active' => true,
                'last_login_at' => now()->subDays(1),
            ]
        );

        VirtualUser::updateOrCreate(
            ['email' => 'devops@techflow.id'],
            [
                'domain_id' => $domainSecond->id,
                'name' => 'Techflow DevOps Lead',
                'password' => $passwordHash,
                'quota_bytes' => 10737418240, // 10 GB
                'used_bytes' => 840000000,
                'maildir_path' => 'techflow.id/devops/',
                'is_active' => true,
                'last_login_at' => now()->subHours(5),
            ]
        );

        // 3. Virtual Aliases
        VirtualAlias::updateOrCreate(
            [
                'domain_id' => $domain->id,
                'source_email' => 'info@perusahaan.net.id',
            ],
            [
                'destination_email' => 'admin@perusahaan.net.id',
                'is_active' => true,
            ]
        );

        VirtualAlias::updateOrCreate(
            [
                'domain_id' => $domain->id,
                'source_email' => 'finance@perusahaan.net.id',
            ],
            [
                'destination_email' => 'siti.aminah@perusahaan.net.id',
                'is_active' => true,
            ]
        );
    }
}
