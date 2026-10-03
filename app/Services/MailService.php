<?php

namespace App\Services;

use Webklex\IMAP\Facades\Client;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use App\Models\VirtualUser;
use Exception;

class MailService
{
    /**
     * Dapatkan koneksi IMAP dinamis sesuai user mailbox yang sedang aktif
     */
    public static function getImapClient(VirtualUser $user, string $password = '')
    {
        $host = config('mail.mailers.smtp.host', '127.0.0.1');
        $port = env('IMAP_PORT', 993);
        $encryption = env('IMAP_ENCRYPTION', 'ssl'); // 'ssl' atau 'tls' atau false

        return Client::make([
            'host'          => $host,
            'port'          => $port,
            'encryption'    => $encryption,
            'validate_cert' => false,
            'username'      => $user->email,
            'password'      => $password,
            'protocol'      => 'imap',
        ]);
    }

    /**
     * Ambil email dari server IMAP Dovecot
     */
    public static function fetchMessages(VirtualUser $user, string $password = '', string $folderName = 'INBOX', int $limit = 20): array
    {
        try {
            $client = self::getImapClient($user, $password);
            $client->connect();

            $folder = $client->getFolder($folderName);
            $messages = $folder->query()->all()->limit($limit)->get();

            $result = [];
            foreach ($messages as $msg) {
                $result[] = [
                    'id' => $msg->getUid(),
                    'folder' => strtolower($folderName),
                    'from_name' => $msg->getFrom()[0]->personal ?? $msg->getFrom()[0]->mail,
                    'from_email' => $msg->getFrom()[0]->mail,
                    'to' => $msg->getTo()[0]->mail ?? '',
                    'subject' => $msg->getSubject() ?? '(Tanpa Subjek)',
                    'date' => $msg->getDate() ? $msg->getDate()->format('d M H:i') : '',
                    'is_read' => $msg->hasFlag('Seen'),
                    'is_starred' => $msg->hasFlag('Flagged'),
                    'body' => $msg->hasHTMLBody() ? strip_tags($msg->getHTMLBody()) : ($msg->getTextBody() ?? ''),
                ];
            }

            return $result;
        } catch (Exception $e) {
            Log::warning("IMAP connection notice: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Kirim email keluar melalui Postfix SMTP atau /usr/sbin/sendmail
     */
    public static function sendOutboundMail(string $fromEmail, string $fromName, string $to, string $subject, string $bodyContent): bool
    {
        $domain = explode('@', $fromEmail)[1] ?? 'sahabatit.my.id';
        $messageId = '<' . time() . '.' . bin2hex(random_bytes(8)) . '@' . $domain . '>';
        $dateRfc2822 = date('r');

        // 1. Coba kirim via Laravel Mailer SMTP / Sendmail
        try {
            Mail::mailer(config('mail.default', 'sendmail'))->html($bodyContent, function ($message) use ($fromEmail, $fromName, $to, $subject, $messageId) {
                $message->from($fromEmail, $fromName)
                        ->to($to)
                        ->subject($subject);
                $message->getHeaders()->addIdHeader('Message-ID', $messageId);
            });
            return true;
        } catch (\Throwable $e) {
            Log::warning("Laravel Mailer Attempt Failed: " . $e->getMessage() . ". Trying fallback pipe to sendmail...");
        }

        // 2. Fallback: Langsung pipe ke binary Postfix sendmail (/usr/sbin/sendmail) di Linux VPS
        if (PHP_OS_FAMILY === 'Linux' && file_exists('/usr/sbin/sendmail')) {
            try {
                // Header lengkap standar RFC 5322 agar 100% lolos filter Gmail / Yahoo
                $headers = "From: {$fromName} <{$fromEmail}>\r\n" .
                           "Reply-To: {$fromName} <{$fromEmail}>\r\n" .
                           "Date: {$dateRfc2822}\r\n" .
                           "Message-ID: {$messageId}\r\n" .
                           "MIME-Version: 1.0\r\n" .
                           "X-Mailer: MailIDS-Webmail/1.0\r\n" .
                           "Content-Type: text/html; charset=UTF-8\r\n" .
                           "Content-Transfer-Encoding: 8bit\r\n";

                $htmlBody = nl2br(htmlspecialchars($bodyContent, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
                $formattedContent = "<!DOCTYPE html><html><head><meta charset='utf-8'></head><body style='font-family: sans-serif; font-size: 14px; color: #333; line-height: 1.6;'>{$htmlBody}</body></html>";

                $rawMsg = "To: {$to}\r\n" .
                          "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n" .
                          $headers . "\r\n" .
                          $formattedContent . "\r\n";

                // Kirim dengan parameter -f agar Return-Path cocok dengan pengirim (SPF alignment)
                $pipe = @popen("/usr/sbin/sendmail -t -i -f " . escapeshellarg($fromEmail), "w");
                if ($pipe) {
                    fwrite($pipe, $rawMsg);
                    $returnCode = pclose($pipe);
                    if ($returnCode === 0) {
                        return true;
                    }
                }
            } catch (\Throwable $fallbackEx) {
                Log::error("Sendmail binary pipe error: " . $fallbackEx->getMessage());
            }
        }

        // 3. Fallback PHP mail()
        try {
            $headers = "From: {$fromName} <{$fromEmail}>\r\n" .
                       "Reply-To: {$fromEmail}\r\n" .
                       "Date: {$dateRfc2822}\r\n" .
                       "Message-ID: {$messageId}\r\n" .
                       "MIME-Version: 1.0\r\n" .
                       "Content-Type: text/plain; charset=UTF-8\r\n" .
                       "X-Mailer: PHP/" . phpversion();
            return @mail($to, $subject, $bodyContent, $headers, "-f " . $fromEmail);
        } catch (\Throwable $lastEx) {
            Log::error("Final mail() fallback failed: " . $lastEx->getMessage());
            return false;
        }
    }
}
