<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
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
            // 1. Perbaiki email di folder sent yang salah mencatat Pengirim / generic name
            $users = VirtualUser::all();
            foreach ($users as $user) {
                MailboxEmail::where('virtual_user_id', $user->id)
                    ->where('folder', 'sent')
                    ->where(function ($q) {
                        $q->where('from_name', 'Pengirim')
                          ->orWhere('from_name', 'sender')
                          ->orWhere('from_name', 'form sender')
                          ->orWhere('from_name', 'from sender')
                          ->orWhere('from_name', 'like', 'unknown%')
                          ->orWhereNull('from_name')
                          ->orWhere('from_name', '');
                    })
                    ->update([
                        'from_email' => $user->email,
                        'from_name'  => $user->name ?: ucwords(explode('@', $user->email)[0]),
                    ]);
            }

            // 2. Perbaiki email di folder lain yang from_name-nya 'Pengirim' atau generic
            $genericEmails = MailboxEmail::where(function ($q) {
                $q->where('from_name', 'Pengirim')
                  ->orWhere('from_name', 'sender')
                  ->orWhere('from_name', 'form sender')
                  ->orWhere('from_name', 'from sender')
                  ->orWhere('from_name', 'like', 'unknown%');
            })->get();

            foreach ($genericEmails as $em) {
                $foundEmail = null;
                $foundName = null;

                // Cek From di dalam body
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

                // Cek Reply-To di dalam body
                if (empty($foundEmail) && !empty($em->body) && preg_match('/(?:^|\n)Reply-To:\s*([^\r\n]+)/i', $em->body, $bm)) {
                    $rawReply = trim($bm[1]);
                    if (preg_match('/^(.*?)\s*<([^>]+)>/', $rawReply, $fbm)) {
                        $nameClean = trim(trim($fbm[1]), '"\' ');
                        $foundName = $foundName ?: ($nameClean ?: null);
                        $foundEmail = strtolower(trim($fbm[2]));
                    } else {
                        $foundEmail = strtolower(trim($rawReply, " \t\n\r\0\x0B\"'<>"));
                    }
                }

                // Cek Return-Path di dalam body
                if (empty($foundEmail) && !empty($em->body) && preg_match('/(?:^|\n)Return-Path:\s*<?([^>\r\n]+)>?/i', $em->body, $bm)) {
                    $foundEmail = strtolower(trim($bm[1]));
                }

                // Cek Forwarded / Reply header (mis. Dari: Budi <budi@...> atau From: Budi <budi@...>)
                if (empty($foundEmail) && !empty($em->body) && preg_match('/(?:Dari|From):\s*([^\r\n<]+)<([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})>/i', $em->body, $bm)) {
                    $nameClean = trim(trim($bm[1]), '"\' ');
                    $foundName = $nameClean ?: null;
                    $foundEmail = strtolower(trim($bm[2]));
                }

                $activeEmail = (!empty($foundEmail) && filter_var($foundEmail, FILTER_VALIDATE_EMAIL) && !str_starts_with($foundEmail, 'unknown@'))
                    ? $foundEmail
                    : (!empty($em->from_email) && !str_starts_with($em->from_email, 'unknown@') ? $em->from_email : null);

                if (!empty($activeEmail)) {
                    if (empty($foundName) || in_array(strtolower($foundName), ['pengirim', 'sender', 'from sender', 'form sender', 'unknown'])) {
                        $prefix = explode('@', $activeEmail)[0];
                        $foundName = ucwords(str_replace(['.', '_', '-'], ' ', $prefix));
                    }
                    $em->update([
                        'from_email' => $activeEmail,
                        'from_name'  => $foundName,
                    ]);
                } else {
                    $em->update([
                        'from_name' => '',
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // Migrasi tidak boleh memutus deployment
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
