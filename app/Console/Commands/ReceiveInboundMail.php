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

        // Cari user pemilik mailbox di database
        $user = VirtualUser::where('email', $toEmail)->where('is_active', true)->first();
        if (!$user) {
            // Coba cari dari alias jika ada
            $alias = \App\Models\VirtualAlias::where('source_email', $toEmail)->where('is_active', true)->first();
            if ($alias) {
                $user = VirtualUser::where('email', $alias->destination_email)->where('is_active', true)->first();
            }
        }

        $extracted = $this->parseMimeBodyAndAttachments($headerStr, $body);

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
                'body' => $extracted['body'],
                'attachments' => $extracted['attachments'],
            ]);
        }

        return Command::SUCCESS;
    }

    /**
     * Memisahkan body HTML/teks dan mengekstrak file lampiran (attachments)
     */
    protected function parseMimeBodyAndAttachments(string $headers, string $body): array
    {
        $attachments = [];
        $htmlPart = null;
        $textPart = null;

        // Cari boundary utama
        $boundary = null;
        if (preg_match('/boundary=["\']?([^"\';\r\n]+)["\']?/i', $headers, $bMatch)) {
            $boundary = trim($bMatch[1]);
        } elseif (preg_match('/--([a-zA-Z0-9_\-\.\/=]{15,})/m', $body, $bMatch)) {
            $boundary = trim($bMatch[1]);
        }

        if ($boundary) {
            $parts = explode('--' . $boundary, $body);
            foreach ($parts as $part) {
                $part = trim($part);
                if (empty($part) || $part === '--') continue;

                $sub = explode("\r\n\r\n", $part, 2);
                if (count($sub) < 2) {
                    $sub = explode("\n\n", $part, 2);
                }

                $partHeader = $sub[0] ?? '';
                $partContent = $sub[1] ?? '';

                // Periksa apakah ini lampiran file (attachment / inline file)
                $filename = null;
                if (preg_match('/filename\*?=["\']?(?:UTF-8\'\')?([^"\';\r\n]+)["\']?/i', $partHeader, $fnMatch)) {
                    $filename = urldecode(trim($fnMatch[1]));
                } elseif (preg_match('/name=["\']?([^"\';\r\n]+)["\']?/i', $partHeader, $fnMatch)) {
                    $filename = trim($fnMatch[1]);
                }

                if ($filename) {
                    // Simpan file lampiran fisik ke storage publik
                    $cleanFileBase = preg_replace('/\s+/', '', $partContent);
                    $fileData = base64_decode($cleanFileBase);
                    if ($fileData !== false) {
                        $safeName = time() . '_' . preg_replace('/[^a-zA-Z0-9\._-]/', '_', $filename);
                        $storagePath = 'attachments/' . $safeName;
                        @\Illuminate\Support\Facades\Storage::disk('public')->put($storagePath, $fileData);

                        $sizeKb = round(strlen($fileData) / 1024, 1);
                        $attachments[] = [
                            'name' => $filename,
                            'size' => $sizeKb > 1024 ? round($sizeKb / 1024, 1) . ' MB' : $sizeKb . ' KB',
                            'url' => \Illuminate\Support\Facades\Storage::url($storagePath),
                        ];
                    }
                    continue;
                }

                // Cek jika ini sub-boundary (multipart/alternative di dalam multipart/mixed)
                if (preg_match('/boundary=["\']?([^"\';\r\n]+)["\']?/i', $partHeader, $innerBMatch)) {
                    $innerRes = $this->parseMimeBodyAndAttachments($partHeader, $partContent);
                    if (!empty($innerRes['body'])) {
                        $htmlPart = $innerRes['body'];
                    }
                    if (!empty($innerRes['attachments'])) {
                        $attachments = array_merge($attachments, $innerRes['attachments']);
                    }
                    continue;
                }

                // Dekode konten teks/HTML
                if (stripos($partHeader, 'base64') !== false) {
                    $decoded = base64_decode(preg_replace('/\s+/', '', $partContent));
                    if ($decoded !== false) $partContent = $decoded;
                } elseif (stripos($partHeader, 'quoted-printable') !== false) {
                    $partContent = quoted_printable_decode($partContent);
                }

                if (stripos($partHeader, 'text/html') !== false) {
                    $htmlPart = trim($partContent);
                } elseif (stripos($partHeader, 'text/plain') !== false && empty($textPart)) {
                    $textPart = trim($partContent);
                }
            }
        }

        $finalBody = !empty($htmlPart) ? $htmlPart : (!empty($textPart) ? $textPart : $body);
        // Bersihkan sisa boundary jika ada
        $finalBody = preg_replace('/--[a-zA-Z0-9_\-\.\/=]{15,}--?/s', '', $finalBody);

        return [
            'body' => trim($finalBody),
            'attachments' => $attachments,
        ];
    }
}
