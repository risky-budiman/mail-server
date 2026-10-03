<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Buat akun admin portal default jika belum ada
        User::updateOrCreate(
            ['email' => 'admin@mailportal.local'],
            [
                'name' => 'Administrator',
                'password' => Hash::make('AdminSecret123!'),
                'email_verified_at' => now(),
            ]
        );

        // Jalankan seeder virtual mailbox & domain
        $this->call(VirtualMailSeeder::class);
    }
}
