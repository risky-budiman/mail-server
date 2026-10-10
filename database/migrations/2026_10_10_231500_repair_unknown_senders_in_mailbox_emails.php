<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\MailboxEmail;
use App\Models\VirtualUser;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('mailbox_emails')) {
            return;
        }

        try {
            // 1. Perbaiki email di folder sent yang salah mencatat unknown@domain.com
            $users = VirtualUser::all();
            foreach ($users as $user) {
                MailboxEmail::where('virtual_user_id', $user->id)
                    ->where('folder', 'sent')
                    ->where(function ($q) {
                        $q->where('from_email', 'unknown@domain.com')
                          ->orWhere('from_email', 'like', 'unknown@%')
                          ->orWhereNull('from_email')
                          ->orWhere('from_email', '');
                    })
                    ->update([
                        'from_email' => $user->email,
                        'from_name'  => $user->name ?: ucwords(explode('@', $user->email)[0]),
                    ]);
            }

            // 2. Perbaiki email yang memiliki header From di dalam body
            $unknownEmails = MailboxEmail::where(function ($q) {
                $q->where('from_email', 'unknown@domain.com')
                  ->orWhere('from_email', 'like', 'unknown@%')
                  ->orWhere('from_name', 'like', 'unknown@%');
            })->get();

            foreach ($unknownEmails as $em) {
                $foundEmail = null;
                $foundName = null;

                if (!empty($em->body) && preg_match('/(?:^|\n)From:\s*([^\r\n]+)/i', $em->body, $bm)) {
                    $rawFrom = trim($bm[1]);
                    if (preg_match('/^(.*?)\s*<([^>]+)>/', $rawFrom, $fbm)) {
                        $nameClean = trim(trim($fbm[1]), '"\' ');
                        $foundName = $nameClean ?: null;
                        $foundEmail = strtolower(trim($fbm[2]));
                    } else {
                        $foundEmail = strtolower(trim($rawFrom, " \t\n\r\0\x0B\"'<>"));
                    }
                }

                if (empty($foundEmail) && !empty($em->body) && preg_match('/(?:^|\n)Return-Path:\s*<?([^>\r\n]+)>?/i', $em->body, $bm)) {
                    $foundEmail = strtolower(trim($bm[1]));
                }

                if (!empty($foundEmail) && filter_var($foundEmail, FILTER_VALIDATE_EMAIL) && !str_starts_with($foundEmail, 'unknown@')) {
                    if (empty($foundName)) {
                        $prefix = explode('@', $foundEmail)[0];
                        $foundName = ucwords(str_replace(['.', '_', '-'], ' ', $prefix));
                    }
                    $em->update([
                        'from_email' => $foundEmail,
                        'from_name'  => $foundName ?: $em->from_name,
                    ]);
                } else {
                    $cleanName = ($em->from_name && !str_starts_with($em->from_name, 'unknown@') && strtolower($em->from_name) !== 'pengirim')
                        ? $em->from_name
                        : 'Pengirim';
                    $em->update([
                        'from_email' => '',
                        'from_name'  => $cleanName,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // Migrasi tidak boleh menggagalkan deployment
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op
    }
};
