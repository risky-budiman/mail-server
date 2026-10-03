<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\VirtualUser;
use App\Models\MailboxEmail;
use Carbon\Carbon;

class ReceiveInboundMail extends Command
{
    /**
     * The name and signature of the console command.
     * Menerima email mentah dari stdin (Postfix Pipe / MDA)
     */
    protected $signature = 'mail:receive {recipient?}';

    /**
     * The console command description.
     */
    protected $description = 'Menerima email mentah RFC 822 dari Postfix via STDIN dan menyimpannya langsung ke database MySQL';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // 1. Baca seluruh isi email mentah dari standard input (STDIN)
        $raw = file_get_contents('php://stdin');
        if (empty($raw)) {
            return Command::SUCCESS;
        }

        $parts = explode("\r\n\r\n", $raw, 2);
        if (count($parts) < 2) {
            $parts = explode("\n\n", $raw, 2);
        }

        $headerStr = $parts[0] ?? '';
        $body = $parts[1] ?? '';

        $lines = preg_split('/\r\n|\r|\n/', $headerStr);
        $headers = [];
        $currentKey = '';

        foreach ($lines as $line) {
            if (preg_match('/^([a-zA-Z0-9\-]+):\s*(.*)$/', $line, $matches)) {
                $currentKey = strtolower($matches[1]);
                $headers[$currentKey] = trim($matches[2]);
            } elseif ($currentKey && preg_match('/^\s+(.*)$/', $line, $matches)) {
                $headers[$currentKey] .= ' ' . trim($matches[1]);
            }
        }

        // Tentukan alamat penerima
        $toEmail = $this->argument('recipient') ?: ($headers['to'] ?? '');
        if (preg_match('/<([^>]+)>/', $toEmail, $m)) {
            $toEmail = trim($m[1]);
        } else {
            $toEmail = trim($toEmail);
        }

        // Tentukan pengirim
        $fromName = '';
        $fromEmail = '';
        if (!empty($headers['from'])) {
            $fromRaw = $headers['from'];
            if (preg_match('/^(.*?)\s*<([^>]+)>/', $fromRaw, $m)) {
                $fromName = trim(trim($m[1]), '"\'');
                $fromEmail = trim($m[2]);
            } else {
                $fromEmail = trim($fromRaw);
                $fromName = $fromEmail;
            }
        }

        // Subjek
        $subject = '(Tanpa Subjek)';
        if (!empty($headers['subject'])) {
            $subject = mb_decode_mimeheader($headers['subject']);
        }

        // Tanggal
        $dateHuman = now()->format('d M, H:i');
        if (!empty($headers['date'])) {
            try {
                $dateHuman = Carbon::parse($headers['date'])->format('d M, H:i');
            } catch (\Throwable $e) {}
        }

        // Ekstraksi pesan murni tanpa boundary
        $cleanBody = $this->extractCleanBody($headerStr, $body);

        // Cari user pemilik mailbox di database
        $user = VirtualUser::where('email', $toEmail)->where('is_active', true)->first();
        if (!$user) {
            // Coba cari dari alias jika ada
            $alias = \App\Models\VirtualAlias::where('source_email', $toEmail)->where('is_active', true)->first();
            if ($alias) {
                $user = VirtualUser::where('email', $alias->destination_email)->where('is_active', true)->first();
            }
        }

        if ($user) {
            MailboxEmail::create([
                'virtual_user_id' => $user->id,
                'folder' => 'inbox',
                'from_name' => $fromName ?: 'Sender',
                'from_email' => $fromEmail ?: 'unknown@domain.com',
                'to' => $user->email,
                'subject' => $subject,
                'date_human' => $dateHuman,
                'is_read' => false,
                'is_starred' => false,
                'body' => $cleanBody,
                'attachments' => [],
            ]);
        }

        return Command::SUCCESS;
    }

    protected function extractCleanBody(string $headers, string $body): string
    {
        $boundary = null;
        if (preg_match('/boundary=["\']?([^"\';\r\n]+)["\']?/i', $headers, $bMatch)) {
            $boundary = trim($bMatch[1]);
        } elseif (preg_match('/--([a-zA-Z0-9_\-\.\/=]{15,})/m', $body, $bMatch)) {
            $boundary = trim($bMatch[1]);
        }

        if ($boundary) {
            $parts = explode('--' . $boundary, $body);
            $htmlPart = null;
            $textPart = null;

            foreach ($parts as $part) {
                $part = trim($part);
                if (empty($part) || $part === '--') continue;

                $subParts = explode("\r\n\r\n", $part, 2);
                if (count($subParts) < 2) {
                    $subParts = explode("\n\n", $part, 2);
                }

                $subHeader = $subParts[0] ?? '';
                $subContent = $subParts[1] ?? '';

                if (stripos($subHeader, 'base64') !== false) {
                    $subContent = base64_decode(preg_replace('/\s+/', '', $subContent)) ?: $subContent;
                } elseif (stripos($subHeader, 'quoted-printable') !== false) {
                    $subContent = quoted_printable_decode($subContent);
                }

                if (stripos($subHeader, 'text/html') !== false) {
                    $htmlPart = trim($subContent);
                } elseif (stripos($subHeader, 'text/plain') !== false) {
                    $textPart = trim($subContent);
                }
            }

            if (!empty($htmlPart)) return $htmlPart;
            if (!empty($textPart)) return $textPart;
        }

        $cleaned = preg_replace('/--[a-zA-Z0-9_\-\.\/=]{15,}--?/s', '', $body);
        $cleaned = preg_replace('/Content-Type:\s*[^;\r\n]+(;\s*charset=[^;\r\n]+)?/i', '', $cleaned);
        $cleaned = preg_replace('/Content-Transfer-Encoding:\s*[^\r\n]+/i', '', $cleaned);

        return trim($cleaned) ?: trim($body);
    }
}
