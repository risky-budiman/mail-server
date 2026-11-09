<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\VirtualUser;
use App\Models\MailboxEmail;

class SyncDbToMaildir extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'mail:export-to-maildir {email? : Alamat email pengguna spesifik}';

    /**
     * The console command description.
     */
    protected $description = 'Menyalin email yang ada di database MySQL ke folder fisik Maildir Dovecot (/var/vmail) agar terbaca oleh HP (IMAP)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $targetEmail = $this->argument('email');
        $query = VirtualUser::query();
        if ($targetEmail) {
            $query->where('email', $targetEmail);
        }

        $users = $query->get();
        if ($users->isEmpty()) {
            $this->error("Pengguna tidak ditemukan.");
            return Command::FAILURE;
        }

        foreach ($users as $user) {
            $this->info(">>> Memproses sinkronisasi Maildir untuk: {$user->email}");
            $domain = $user->domain ? $user->domain->name : (explode('@', $user->email)[1] ?? 'localhost');
            $localPart = explode('@', $user->email)[0];
            $maildirBase = '/var/vmail/' . ($domain ? "{$domain}/{$localPart}" : ltrim($user->maildir_path, '/'));

            if (!is_dir($maildirBase)) {
                @mkdir($maildirBase, 0775, true);
            }

            // Pastikan subfolder standar Maildir ada
            $folders = [
                'inbox'  => "{$maildirBase}/cur",
                'sent'   => "{$maildirBase}/.Sent/cur",
                'drafts' => "{$maildirBase}/.Drafts/cur",
                'trash'  => "{$maildirBase}/.Trash/cur",
                'spam'   => "{$maildirBase}/.Junk/cur",
            ];

            foreach ($folders as $fPath) {
                if (!is_dir($fPath)) {
                    @mkdir($fPath, 0775, true);
                }
            }

            // Ambil semua email database milik user ini
            $dbEmails = MailboxEmail::where('virtual_user_id', $user->id)->get();
            $written = 0;
            $skipped = 0;

            foreach ($dbEmails as $email) {
                $fType = strtolower($email->folder ?: 'inbox');
                $targetDir = $folders[$fType] ?? $folders['inbox'];

                // Cek apakah file untuk ID email ini sudah pernah ditulis sebelumnya
                $pattern = "{$targetDir}/*db_{$email->id}*";
                $existing = glob($pattern);
                if (!empty($existing)) {
                    $skipped++;
                    continue;
                }

                $fromName = $email->from_name;
                $fromEmail = $email->from_email ?: $user->email;
                $from = $fromName ? "{$fromName} <{$fromEmail}>" : $fromEmail;
                $to = $email->to ?: $user->email;
                $subject = $email->subject ?: '(Tanpa Subjek)';
                $date = $email->created_at ? $email->created_at->format('r') : date('r');
                $body = $email->body ?: '';

                $isHtml = strip_tags($body) !== $body || str_contains($body, '<html') || str_contains($body, '<div') || str_contains($body, '<p') || str_contains($body, '<br');
                $contentType = $isHtml ? "text/html; charset=utf-8" : "text/plain; charset=utf-8";

                $eml = "From: {$from}\r\n";
                $eml .= "To: {$to}\r\n";
                $eml .= "Subject: {$subject}\r\n";
                $eml .= "Date: {$date}\r\n";
                $eml .= "Message-ID: <db_{$email->id}." . time() . "@{$domain}>\r\n";
                $eml .= "MIME-Version: 1.0\r\n";
                $eml .= "Content-Type: {$contentType}\r\n";
                $eml .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
                $eml .= $body;

                $filename = time() . ".db_{$email->id}:2,S";
                @file_put_contents("{$targetDir}/{$filename}", $eml);
                $written++;
            }

            $this->info("   -> Berhasil mengekspor: {$written} email baru ke Maildir (Dilewati: {$skipped} email yang sudah ada).");

            // Perbaiki kepemilikan dan hak akses
            @shell_exec("chown -R vmail:vmail " . escapeshellarg($maildirBase));
            @shell_exec("chmod -R 775 " . escapeshellarg($maildirBase));

            // Perintahkan Dovecot untuk mengindeks ulang
            @shell_exec("doveadm index -u " . escapeshellarg($user->email) . " '*' 2>/dev/null");
        }

        $this->info(">>> Sinkronisasi selesai! Seluruh email di database kini sudah tersimpan di harddisk Maildir.");
        return Command::SUCCESS;
    }
}

