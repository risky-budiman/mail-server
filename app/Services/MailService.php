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
     * Mendukung lampiran file (PDF, Dokumen Office, Gambar, Zip, dll)
     * @param array $attachments Array of file paths or uploaded files or ['path' => string, 'name' => string]
     */
    public static function sendOutboundMail(
        string $fromEmail,
        string $fromName,
        string|array $to,
        string $subject,
        string $bodyContent,
        array $attachments = [],
        string|array $cc = '',
        string|array $bcc = ''
    ): bool {
        $domain = explode('@', $fromEmail)[1] ?? 'sahabatit.my.id';
        $messageId = '<' . time() . '.' . bin2hex(random_bytes(8)) . '@' . $domain . '>';
        $dateRfc2822 = date('r');

        $normalize = function($input) {
            if (is_array($input)) {
                $raw = implode(', ', $input);
            } else {
                $raw = (string)$input;
            }
            $parts = preg_split('/[,;]+/', $raw);
            $clean = [];
            foreach ($parts as $p) {
                $p = trim($p);
                if ($p !== '') {
                    $clean[] = $p;
                }
            }
            return array_values(array_unique($clean));
        };

        $toList = $normalize($to);
        $ccList = $normalize($cc);
        $bccList = $normalize($bcc);

        $toHeader = implode(', ', $toList);
        $ccHeader = implode(', ', $ccList);
        $bccHeader = implode(', ', $bccList);

        $recipientHeaderStr = "To: {$toHeader}\r\n";
        if (!empty($ccHeader)) {
            $recipientHeaderStr .= "Cc: {$ccHeader}\r\n";
        }
        if (!empty($bccHeader)) {
            $recipientHeaderStr .= "Bcc: {$bccHeader}\r\n";
        }

        // 1. Pada Linux VPS dengan Postfix (/usr/sbin/sendmail), gunakan pipe biner langsung ke engine Postfix
        // Ini memastikan email langsung masuk ke antrean Postfix tanpa terhalang setelan MAIL_MAILER=log di .env
        if (PHP_OS_FAMILY === 'Linux' && file_exists('/usr/sbin/sendmail')) {
            try {
                $mixedBoundary = "==_MailIDS_Mixed_" . md5(uniqid(microtime(true)));
                $altBoundary = "==_MailIDS_Alt_" . md5(uniqid(microtime(true) . 'alt'));

                $plainText = trim(strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $bodyContent)));
                $htmlBody = nl2br(htmlspecialchars($bodyContent, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
                $formattedContent = "<!DOCTYPE html><html><head><meta charset='utf-8'></head><body style='font-family: sans-serif; font-size: 14px; color: #333; line-height: 1.6;'>{$htmlBody}</body></html>";

                $headers = "From: {$fromName} <{$fromEmail}>\r\n" .
                           "Reply-To: {$fromName} <{$fromEmail}>\r\n" .
                           "Date: {$dateRfc2822}\r\n" .
                           "Message-ID: {$messageId}\r\n" .
                           "MIME-Version: 1.0\r\n" .
                           "X-Mailer: MailIDS-Webmail/1.0\r\n";

                // Sub-blok multipart/alternative yang memuat text/plain dan text/html
                $alternativeBody = "--{$altBoundary}\r\n" .
                                   "Content-Type: text/plain; charset=UTF-8\r\n" .
                                   "Content-Transfer-Encoding: 8bit\r\n\r\n" .
                                   $plainText . "\r\n\r\n" .
                                   "--{$altBoundary}\r\n" .
                                   "Content-Type: text/html; charset=UTF-8\r\n" .
                                   "Content-Transfer-Encoding: 8bit\r\n\r\n" .
                                   $formattedContent . "\r\n\r\n" .
                                   "--{$altBoundary}--\r\n";

                if (!empty($attachments)) {
                    $headers .= "Content-Type: multipart/mixed; boundary=\"{$mixedBoundary}\"\r\n";
                    $rawMsg = $recipientHeaderStr .
                              "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n" .
                              $headers . "\r\n" .
                              "--{$mixedBoundary}\r\n" .
                              "Content-Type: multipart/alternative; boundary=\"{$altBoundary}\"\r\n\r\n" .
                              $alternativeBody . "\r\n";

                    foreach ($attachments as $att) {
                        $filePath = null;
                        $fileName = 'attachment';
                        if (is_string($att) && file_exists($att)) {
                            $filePath = $att;
                            $fileName = basename($att);
                        } elseif (is_array($att)) {
                            if (!empty($att['path']) && file_exists($att['path'])) {
                                $filePath = $att['path'];
                            } elseif (!empty($att['storage_path']) && \Illuminate\Support\Facades\Storage::disk('public')->exists($att['storage_path'])) {
                                $filePath = \Illuminate\Support\Facades\Storage::disk('public')->path($att['storage_path']);
                            } elseif (!empty($att['path']) && \Illuminate\Support\Facades\Storage::disk('public')->exists($att['path'])) {
                                $filePath = \Illuminate\Support\Facades\Storage::disk('public')->path($att['path']);
                            }
                            $fileName = $att['name'] ?? basename($filePath ?: 'attachment');
                        } elseif (is_object($att) && method_exists($att, 'getRealPath')) {
                            $filePath = $att->getRealPath();
                            $fileName = method_exists($att, 'getClientOriginalName') ? $att->getClientOriginalName() : basename($filePath);
                        }

                        if ($filePath && file_exists($filePath)) {
                            $mime = mime_content_type($filePath) ?: 'application/octet-stream';
                            $fileData = chunk_split(base64_encode(file_get_contents($filePath)));
                            $rawMsg .= "--{$mixedBoundary}\r\n" .
                                       "Content-Type: {$mime}; name=\"{$fileName}\"\r\n" .
                                       "Content-Disposition: attachment; filename=\"{$fileName}\"\r\n" .
                                       "Content-Transfer-Encoding: base64\r\n\r\n" .
                                       $fileData . "\r\n";
                        }
                    }
                    $rawMsg .= "--{$mixedBoundary}--\r\n";
                } else {
                    $headers .= "Content-Type: multipart/alternative; boundary=\"{$altBoundary}\"\r\n";
                    $rawMsg = $recipientHeaderStr .
                              "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n" .
                              $headers . "\r\n" .
                              $alternativeBody;
                }

                // Kirim langsung ke Postfix sendmail dengan flag -f untuk SPF alignment
                $pipe = @popen("/usr/sbin/sendmail -t -i -f " . escapeshellarg($fromEmail), "w");
                if ($pipe) {
                    fwrite($pipe, $rawMsg);
                    $returnCode = pclose($pipe);
                    if ($returnCode === 0) {
                        Log::info("Email berhasil diserahkan ke Postfix via /usr/sbin/sendmail untuk: {$toHeader} (CC: {$ccHeader}, BCC: {$bccHeader})");
                        return true;
                    }
                }
            } catch (\Throwable $fallbackEx) {
                Log::warning("Sendmail binary pipe notice: " . $fallbackEx->getMessage() . ". Mencoba Laravel Mailer...");
            }
        }

        // 2. Coba kirim via Laravel Mailer SMTP / Sendmail
        $configuredMailer = config('mail.default', 'sendmail');
        if ($configuredMailer !== 'log') {
            try {
                $plainText = trim(strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $bodyContent)));
                $htmlBody = nl2br(htmlspecialchars($bodyContent, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
                $formattedHtml = "<!DOCTYPE html><html><head><meta charset='utf-8'></head><body style='font-family: sans-serif; font-size: 14px; color: #333; line-height: 1.6;'>{$htmlBody}</body></html>";

                Mail::mailer($configuredMailer)->send([], [], function ($message) use ($fromEmail, $fromName, $toList, $ccList, $bccList, $subject, $messageId, $attachments, $plainText, $formattedHtml) {
                    $message->from($fromEmail, $fromName)
                            ->to($toList)
                            ->subject($subject)
                            ->text($plainText)
                            ->html($formattedHtml);

                    if (!empty($ccList)) {
                        $message->cc($ccList);
                    }
                    if (!empty($bccList)) {
                        $message->bcc($bccList);
                    }

                    $message->getHeaders()->addIdHeader('Message-ID', $messageId);

                    // Lampirkan file jika ada
                    foreach ($attachments as $att) {
                        if (is_string($att) && file_exists($att)) {
                            $message->attach($att);
                        } elseif (is_array($att)) {
                            $filePath = null;
                            if (!empty($att['path']) && file_exists($att['path'])) {
                                $filePath = $att['path'];
                            } elseif (!empty($att['storage_path']) && \Illuminate\Support\Facades\Storage::disk('public')->exists($att['storage_path'])) {
                                $filePath = \Illuminate\Support\Facades\Storage::disk('public')->path($att['storage_path']);
                            } elseif (!empty($att['path']) && \Illuminate\Support\Facades\Storage::disk('public')->exists($att['path'])) {
                                $filePath = \Illuminate\Support\Facades\Storage::disk('public')->path($att['path']);
                            }
                            if ($filePath && file_exists($filePath)) {
                                $message->attach($filePath, ['as' => $att['name'] ?? basename($filePath)]);
                            }
                        } elseif (is_object($att) && method_exists($att, 'getRealPath') && file_exists($att->getRealPath())) {
                            $message->attach($att->getRealPath(), [
                                'as' => method_exists($att, 'getClientOriginalName') ? $att->getClientOriginalName() : basename($att->getRealPath()),
                            ]);
                        }
                    }
                });
                return true;
            } catch (\Throwable $e) {
                Log::warning("Laravel Mailer Attempt Failed: " . $e->getMessage());
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

            if (!empty($ccHeader)) {
                $headers .= "\r\nCc: {$ccHeader}";
            }
            if (!empty($bccHeader)) {
                $headers .= "\r\nBcc: {$bccHeader}";
            }

            return @mail($toHeader, $subject, $bodyContent, $headers, "-f " . $fromEmail);
        } catch (\Throwable $lastEx) {
            Log::error("Final mail() fallback failed: " . $lastEx->getMessage());
            return false;
        }
    }
}
