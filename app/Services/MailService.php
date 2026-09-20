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
     * Kirim email keluar melalui Postfix SMTP
     */
    public static function sendOutboundMail(string $fromEmail, string $fromName, string $to, string $subject, string $bodyContent): bool
    {
        try {
            Mail::raw($bodyContent, function ($message) use ($fromEmail, $fromName, $to, $subject) {
                $message->from($fromEmail, $fromName)
                        ->to($to)
                        ->subject($subject);
            });
            return true;
        } catch (Exception $e) {
            Log::error("SMTP Outbound Error: " . $e->getMessage());
            return false;
        }
    }
}
