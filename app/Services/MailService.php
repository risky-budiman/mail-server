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
        // 1. Coba kirim via Laravel Mailer terlebih dahulu
        try {
            Mail::mailer(config('mail.default', 'sendmail'))->raw($bodyContent, function ($message) use ($fromEmail, $fromName, $to, $subject) {
                $message->from($fromEmail, $fromName)
                        ->to($to)
                        ->subject($subject);
            });
            return true;
        } catch (\Throwable $e) {
            Log::warning("Laravel Mailer Attempt Failed: " . $e->getMessage() . ". Trying fallback pipe to sendmail...");
        }

        // 2. Fallback: Langsung pipe ke binary Postfix sendmail (/usr/sbin/sendmail) di Linux VPS
        if (PHP_OS_FAMILY === 'Linux' && file_exists('/usr/sbin/sendmail')) {
            try {
                $headers = "From: {$fromName} <{$fromEmail}>\r\n" .
                           "Reply-To: {$fromEmail}\r\n" .
                           "X-Mailer: MailIDS-Engine/1.0\r\n" .
                           "Content-Type: text/plain; charset=utf-8\r\n";

                $rawMsg = "To: {$to}\r\n" .
                          $headers .
                          "Subject: {$subject}\r\n\r\n" .
                          $bodyContent . "\r\n";

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
                       "X-Mailer: PHP/" . phpversion();
            return @mail($to, $subject, $bodyContent, $headers, "-f " . $fromEmail);
        } catch (\Throwable $lastEx) {
            Log::error("Final mail() fallback failed: " . $lastEx->getMessage());
            return false;
        }
    }
}
