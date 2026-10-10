<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\VirtualUser;
use App\Models\VirtualAlias;
use App\Models\MailboxEmail;
use App\Models\MailboxContact;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class ReceiveInboundMail extends Command
{
    /**
     * The name and signature of the console command.
     * Menerima email mentah dari stdin (Postfix Pipe / MDA)
     */
    protected $signature = 'mail:receive {recipient?} {--raw= : Konten email mentah secara langsung}';

    /**
     * The console command description.
     */
    protected $description = 'Menerima email mentah RFC 822 dari Postfix via STDIN atau argumen --raw dan menyimpannya langsung ke database MySQL';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // 1. Baca seluruh isi email mentah dari opsi --raw atau standard input (STDIN)
        $raw = $this->option('raw') ?: @file_get_contents('php://stdin');
        if (empty($raw)) {
            return Command::SUCCESS;
        }

        $parts = explode("\r\n\r\n", $raw, 2);
        if (count($parts) < 2) {
            $parts = explode("\n\n", $raw, 2);
        }

        $headerStr = $parts[0] ?? '';
        $body = $parts[1] ?? '';

        // Unfold headers (gabungkan baris lanjutan RFC 822)
        $unfoldedHeaderStr = preg_replace('/\r?\n[ \t]+/', ' ', $headerStr);
        $lines = preg_split('/\r?\n/', $unfoldedHeaderStr);
        $headers = [];

        foreach ($lines as $line) {
            if (preg_match('/^([a-zA-Z0-9\-]+):\s*(.*)$/', $line, $matches)) {
                $currentKey = strtolower($matches[1]);
                $headers[$currentKey] = trim($matches[2]);
            }
        }

        // Tentukan alamat penerima
        $toEmail = $this->argument('recipient') ?: ($headers['to'] ?? '');
        if (preg_match('/<([^>]+)>/', $toEmail, $m)) {
            $toEmail = strtolower(trim($m[1]));
        } else {
            $toEmail = strtolower(trim($toEmail));
        }

        // Tentukan pengirim
        $fromName = '';
        $fromEmail = '';
        if (!empty($headers['from'])) {
            $fromRaw = $headers['from'];
            if (preg_match('/^(.*?)\s*<([^>]+)>/', $fromRaw, $m)) {
                $fromName = $this->decodeMimeString(trim(trim($m[1]), '"\''));
                $fromEmail = strtolower(trim($m[2]));
            } else {
                $fromEmail = strtolower(trim($fromRaw, " \t\n\r\0\x0B\"'<>"));
                $fromName = $fromEmail;
            }
        } elseif (!empty($headers['sender'])) {
            $rawSender = $headers['sender'];
            if (preg_match('/^(.*?)\s*<([^>]+)>/', $rawSender, $m)) {
                $fromName = $this->decodeMimeString(trim(trim($m[1]), '"\''));
                $fromEmail = strtolower(trim($m[2]));
            } else {
                $fromEmail = strtolower(trim($rawSender, " \t\n\r\0\x0B\"'<>"));
            }
        } elseif (!empty($headers['reply-to'])) {
            $rawReply = $headers['reply-to'];
            if (preg_match('/^(.*?)\s*<([^>]+)>/', $rawReply, $m)) {
                $fromEmail = strtolower(trim($m[2]));
            } else {
                $fromEmail = strtolower(trim($rawReply, " \t\n\r\0\x0B\"'<>"));
            }
        } elseif (!empty($headers['return-path'])) {
            $fromEmail = strtolower(trim($headers['return-path'], " \t\n\r\0\x0B\"'<>"));
        }

        if (empty($fromName) || in_array(strtolower(trim($fromName)), ['sender', 'pengirim', 'unknown', 'from sender', 'form sender'])) {
            if (!empty($fromEmail) && !str_starts_with($fromEmail, 'unknown@')) {
                $prefix = explode('@', $fromEmail)[0];
                $fromName = ucwords(str_replace(['.', '_', '-'], ' ', $prefix));
            } else {
                $fromName = 'Pengirim';
            }
        }

        // Subjek dengan decode MIME lengkap (RFC 2047)
        $subject = '(Tanpa Subjek)';
        if (!empty($headers['subject'])) {
            $subject = $this->decodeMimeString($headers['subject']);
        }

        // Tanggal
        $dateHuman = now()->format('d M, H:i');
        if (!empty($headers['date'])) {
            try {
                $dateHuman = Carbon::parse($headers['date'])->format('d M, H:i');
            } catch (\Throwable $e) {}
        }

        // 2. Kumpulkan seluruh akun mailbox lokal yang menjadi penerima (mendukung multi-alias & direct mailbox)
        $targetUserIds = [];
        
        // Cek jika penerima adalah akun mailbox langsung
        $directUser = VirtualUser::where('email', $toEmail)->where('is_active', true)->first();
        if ($directUser) {
            $targetUserIds[] = $directUser->id;
        }

        // Cek jika penerima adalah alias yang diarahkan ke akun lokal
        $aliases = VirtualAlias::where('source_email', $toEmail)->where('is_active', true)->get();
        foreach ($aliases as $al) {
            // Destination bisa berupa satu email atau beberapa email terpisah koma
            $destinations = array_filter(array_map('trim', explode(',', $al->destination_email)));
            foreach ($destinations as $dest) {
                $matchedUser = VirtualUser::where('email', strtolower($dest))->where('is_active', true)->first();
                if ($matchedUser && !in_array($matchedUser->id, $targetUserIds)) {
                    $targetUserIds[] = $matchedUser->id;
                }
            }
        }

        // Ekstraksi MIME Body dan Lampiran
        $extracted = $this->parseMimeBodyAndAttachments($headerStr, $body);

        // Simpan email ke setiap akun mailbox penerima yang valid
        foreach ($targetUserIds as $uId) {
            $user = VirtualUser::find($uId);
            if (!$user) continue;

            // Salin lampiran ke folder khusus user jika diperlukan
            $userAttachments = $extracted['attachments'];

            MailboxEmail::create([
                'virtual_user_id' => $user->id,
                'folder' => 'inbox',
                'from_name' => $fromName ?: 'Pengirim',
                'from_email' => $fromEmail ?: '',
                'to' => $toEmail ?: $user->email,
                'subject' => $subject,
                'date_human' => $dateHuman,
                'is_read' => false,
                'is_starred' => false,
                'body' => $extracted['body'],
                'attachments' => $userAttachments,
            ]);

            // Otomatis simpan kontak yang pernah berkomunikasi
            if ($fromEmail && !str_starts_with($fromEmail, 'unknown@')) {
                MailboxContact::recordCommunication($user->id, $fromEmail, $fromName);
            }

            // Sinkronkan kapasitas disk usage
            $actualBytes = strlen($extracted['body']);
            foreach ($userAttachments as $ua) {
                $actualBytes += ($ua['bytes'] ?? 150000);
            }
            $user->syncMaildirDiskUsage($actualBytes);
        }

        return Command::SUCCESS;
    }

    /**
     * Decode MIME RFC 2047 string (Q-encoding & B-encoding)
     */
    protected function decodeMimeString(string $string): string
    {
        $decoded = iconv_mime_decode($string, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        if ($decoded !== false && !empty($decoded)) {
            return $decoded;
        }
        return mb_decode_mimeheader($string) ?: $string;
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

                // Ekstrak nama file lampiran dengan dukungan RFC 2231 / RFC 2047
                $filename = $this->extractFilenameFromPartHeader($partHeader);

                if ($filename) {
                    $fileData = $this->decodePartContent($partHeader, $partContent);
                    if ($fileData !== false && strlen($fileData) > 0) {
                        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION) ?: 'dat');
                        $safeName = time() . '_' . bin2hex(random_bytes(4)) . '_' . preg_replace('/[^a-zA-Z0-9\._-]/', '_', $filename);
                        $storagePath = 'attachments/' . $safeName;
                        
                        Storage::disk('public')->put($storagePath, $fileData);

                        $sizeBytes = strlen($fileData);
                        $sizeKb = round($sizeBytes / 1024, 1);
                        $formattedSize = $sizeKb > 1024 ? round($sizeKb / 1024, 1) . ' MB' : $sizeKb . ' KB';

                        $attachments[] = [
                            'name' => $filename,
                            'size' => $formattedSize,
                            'ext'  => $ext,
                            'path' => $storagePath,
                            'url'  => Storage::url($storagePath),
                            'bytes' => $sizeBytes,
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
                $decodedContent = $this->decodePartContent($partHeader, $partContent);

                if (stripos($partHeader, 'text/html') !== false) {
                    $htmlPart = trim($decodedContent);
                } elseif (stripos($partHeader, 'text/plain') !== false && empty($textPart)) {
                    $textPart = trim($decodedContent);
                }
            }
        }

        $finalBody = !empty($htmlPart) ? $htmlPart : (!empty($textPart) ? $textPart : $body);
        $finalBody = preg_replace('/--[a-zA-Z0-9_\-\.\/=]{15,}--?/s', '', $finalBody);

        return [
            'body' => trim($finalBody),
            'attachments' => $attachments,
        ];
    }

    /**
     * Ekstrak nama file lampiran dari MIME header part
     */
    protected function extractFilenameFromPartHeader(string $header): ?string
    {
        $filename = null;

        // RFC 2231 / RFC 5987: filename*=UTF-8''...
        if (preg_match('/filename\*=(?:[a-zA-Z0-9_\-]+\'\')?([^;\r\n]+)/i', $header, $m)) {
            $filename = urldecode(trim(trim($m[1]), '"\''));
        }
        // Standar filename="..." atau filename=...
        elseif (preg_match('/filename=["\']?([^"\'\r\n;]+)["\']?/i', $header, $m)) {
            $filename = trim($m[1]);
        }
        // Standar name="..." atau name=...
        elseif (preg_match('/name=["\']?([^"\'\r\n;]+)["\']?/i', $header, $m)) {
            $filename = trim($m[1]);
        }

        if ($filename) {
            $filename = $this->decodeMimeString($filename);
            return basename($filename);
        }

        return null;
    }

    /**
     * Dekode konten part sesuai Content-Transfer-Encoding
     */
    protected function decodePartContent(string $header, string $content): string
    {
        if (stripos($header, 'Content-Transfer-Encoding: base64') !== false) {
            $cleaned = preg_replace('/\s+/', '', $content);
            $decoded = base64_decode($cleaned);
            return ($decoded !== false) ? $decoded : $content;
        }

        if (stripos($header, 'Content-Transfer-Encoding: quoted-printable') !== false) {
            return quoted_printable_decode($content);
        }

        return $content;
    }
}
