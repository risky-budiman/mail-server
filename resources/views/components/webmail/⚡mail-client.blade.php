<?php

use Livewire\Component;
use Livewire\WithFileUploads;
use App\Models\VirtualUser;
use App\Models\MailboxEmail;
use App\Models\MailboxFolder;
use App\Models\MailboxContact;

new class extends Component
{
    use WithFileUploads;

    public $activeFolder = 'inbox'; // inbox, sent, drafts, spam, trash, contacts, or custom folder name
    public $selectedEmailId = null;
    public $viewMode = 'list'; // 'list' or 'detail' (Gmail/Hostinger style)
    public $activeFilter = 'all'; // 'all', 'unread', 'read', 'starred'
    public $selectedIds = []; // Checkbox select all / multiple
    public $showComposeModal = false;
    public $currentAccount = null;

    // Contact state (Buku Kontak otomatis dari riwayat & komunikasi)
    public $contactSearchQuery = '';
    public $showContactModal = false;
    public $editingContactId = null;
    public $contactName = '';
    public $contactEmail = '';
    public $contactPhone = '';
    public $contactCompany = '';
    public $contactNotes = '';

    // Search and filter state
    public $searchQuery = '';
    public $filterStarred = false;
    public $filterUnread = false;

    // Folder pengguna sesuai kebutuhan (tanpa folder default)
    public $customFolders = [];
    public $newFolderName = '';
    public $showCreateFolderModal = false;

    // Compose state
    public $composeTo = '';
    public $composeFrom = ''; // Akun utama atau alias yang dipilih
    public $composeCc = '';
    public $composeBcc = '';
    public $showCcBcc = false;
    public $composeSubject = '';
    public $composeBody = '';
    public $attachments = [];
    public $editingDraftId = null; // ID draft jika sedang mengedit draft yang ada
    public $availableAliases = []; // Daftar alias yang terhubung ke akun mailbox ini

    // Quick Reply inline state (Balas Cepat / Teruskan Langsung di Bawah Email)
    public $inlineReplyMode = 'reply'; // 'reply' or 'forward'
    public $showInlineReply = false;
    public $quickReplyFrom = ''; // Alamat pengirim untuk balasan (bisa alias)
    public $quickReplyTo = '';
    public $quickReplyCc = '';
    public $quickReplyBcc = '';
    public $quickReplySubject = '';
    public $showInlineCcBcc = false;
    public $quickReplyText = '';
    public $quickReplyAttachments = [];

    // User Profile / Settings modal state
    public $showSettingsModal = false;
    public $settingsTab = 'profile'; // 'profile' or 'password'
    public $settingsName = '';
    public $settingsCurrentPassword = '';
    public $settingsNewPassword = '';
    public $settingsNewPasswordConfirmation = '';

    public function openSettingsModal()
    {
        $user = $this->getAccount();
        if ($user) {
            $this->settingsName = $user->name ?? '';
            $this->settingsCurrentPassword = '';
            $this->settingsNewPassword = '';
            $this->settingsNewPasswordConfirmation = '';
            $this->settingsTab = 'profile';
            $this->showSettingsModal = true;
        }
    }

    public function closeSettingsModal()
    {
        $this->showSettingsModal = false;
        $this->reset(['settingsCurrentPassword', 'settingsNewPassword', 'settingsNewPasswordConfirmation']);
    }

    public function updateProfile()
    {
        $this->validate([
            'settingsName' => 'required|string|min:2|max:100',
        ]);

        $user = $this->getAccount();
        if ($user) {
            $user->name = trim($this->settingsName);
            $user->save();
            $this->currentAccount = $user->fresh();
            session()->flash('settings_success', 'Profil nama pengguna berhasil diperbarui!');
        }
    }

    public function updatePassword()
    {
        $this->validate([
            'settingsCurrentPassword' => 'required|string',
            'settingsNewPassword' => 'required|string|min:6|same:settingsNewPasswordConfirmation',
        ], [
            'settingsCurrentPassword.required' => 'Password saat ini harus diisi.',
            'settingsNewPassword.required' => 'Password baru harus diisi.',
            'settingsNewPassword.min' => 'Password baru minimal 6 karakter.',
            'settingsNewPassword.same' => 'Konfirmasi password baru tidak cocok.',
        ]);

        $user = $this->getAccount();
        if ($user) {
            if (!\Illuminate\Support\Facades\Hash::check($this->settingsCurrentPassword, $user->password)) {
                $this->addError('settingsCurrentPassword', 'Password saat ini salah!');
                return;
            }

            $user->password = \Illuminate\Support\Facades\Hash::make($this->settingsNewPassword);
            $user->save();

            $this->reset(['settingsCurrentPassword', 'settingsNewPassword', 'settingsNewPasswordConfirmation']);
            session()->flash('settings_success', 'Password mailbox berhasil diubah!');
        }
    }

    // ==========================================
    // METODE PENGELOLAAN KONTAK OTOMATIS & MANUAL
    // ==========================================
    public function syncContactsFromHistory()
    {
        $user = $this->getAccount();
        if (!$user) return;

        // Ambil semua pengirim dari inbox / folder lain yang pernah masuk
        $incoming = MailboxEmail::where('virtual_user_id', $user->id)
            ->where('from_email', '!=', $user->email)
            ->whereNotNull('from_email')
            ->get(['from_email', 'from_name', 'created_at']);

        foreach ($incoming as $item) {
            MailboxContact::recordCommunication($user->id, $item->from_email, $item->from_name, $item->created_at);
        }

        // Ambil semua penerima dari email terkirim
        $outgoing = MailboxEmail::where('virtual_user_id', $user->id)
            ->where('folder', 'sent')
            ->whereNotNull('to')
            ->get(['to', 'created_at']);

        foreach ($outgoing as $item) {
            $recipients = preg_split('/[,;\s]+/', $item->to);
            foreach ($recipients as $rec) {
                if (!empty($rec) && $rec !== $user->email) {
                    MailboxContact::recordCommunication($user->id, $rec, null, $item->created_at);
                }
            }
        }
    }

    public function openCreateContactModal()
    {
        $this->editingContactId = null;
        $this->contactName = '';
        $this->contactEmail = '';
        $this->contactPhone = '';
        $this->contactCompany = '';
        $this->contactNotes = '';
        $this->showContactModal = true;
    }

    public function openEditContactModal($id)
    {
        $user = $this->getAccount();
        if (!$user) return;

        $contact = MailboxContact::where('virtual_user_id', $user->id)->find($id);
        if ($contact) {
            $this->editingContactId = $contact->id;
            $this->contactName = $contact->name ?? '';
            $this->contactEmail = $contact->email ?? '';
            $this->contactPhone = $contact->phone ?? '';
            $this->contactCompany = $contact->company ?? '';
            $this->contactNotes = $contact->notes ?? '';
            $this->showContactModal = true;
        }
    }

    public function closeContactModal()
    {
        $this->showContactModal = false;
        $this->editingContactId = null;
        $this->reset(['contactName', 'contactEmail', 'contactPhone', 'contactCompany', 'contactNotes']);
    }

    public function saveContact()
    {
        $this->validate([
            'contactName' => 'nullable|string|max:100',
            'contactEmail' => 'required|email|max:100',
            'contactPhone' => 'nullable|string|max:30',
            'contactCompany' => 'nullable|string|max:100',
            'contactNotes' => 'nullable|string|max:500',
        ], [
            'contactEmail.required' => 'Alamat email kontak wajib diisi.',
            'contactEmail.email' => 'Format email kontak tidak valid.',
        ]);

        $user = $this->getAccount();
        if (!$user) return;

        $cleanEmail = strtolower(trim($this->contactEmail));
        $cleanName = trim($this->contactName) ?: explode('@', $cleanEmail)[0];

        if ($this->editingContactId) {
            $contact = MailboxContact::where('virtual_user_id', $user->id)->find($this->editingContactId);
            if ($contact) {
                $contact->update([
                    'name' => $cleanName,
                    'email' => $cleanEmail,
                    'phone' => trim($this->contactPhone) ?: null,
                    'company' => trim($this->contactCompany) ?: null,
                    'notes' => trim($this->contactNotes) ?: null,
                ]);
                session()->flash('contact_success', "Kontak '{$cleanName}' berhasil diperbarui!");
            }
        } else {
            $contact = MailboxContact::where('virtual_user_id', $user->id)
                ->where('email', $cleanEmail)
                ->first();

            if ($contact) {
                $contact->update([
                    'name' => $cleanName,
                    'phone' => trim($this->contactPhone) ?: $contact->phone,
                    'company' => trim($this->contactCompany) ?: $contact->company,
                    'notes' => trim($this->contactNotes) ?: $contact->notes,
                ]);
            } else {
                MailboxContact::create([
                    'virtual_user_id' => $user->id,
                    'name' => $cleanName,
                    'email' => $cleanEmail,
                    'phone' => trim($this->contactPhone) ?: null,
                    'company' => trim($this->contactCompany) ?: null,
                    'notes' => trim($this->contactNotes) ?: null,
                    'last_communicated_at' => now(),
                    'communication_count' => 1,
                ]);
            }
            session()->flash('contact_success', "Kontak '{$cleanName}' berhasil disimpan!");
        }

        $this->closeContactModal();
    }

    public function deleteContact($id)
    {
        $user = $this->getAccount();
        if (!$user) return;

        $contact = MailboxContact::where('virtual_user_id', $user->id)->find($id);
        if ($contact) {
            $name = $contact->name ?: $contact->email;
            $contact->delete();
            session()->flash('contact_success', "Kontak '{$name}' berhasil dihapus.");
        }
    }

    public function composeToContact($email)
    {
        $this->composeTo = $email;
        $this->showComposeModal = true;
    }

    protected function getAccount()
    {
        return auth('mailbox')->user() ?? VirtualUser::first();
    }

    public function mount()
    {
        $user = $this->getAccount();
        $this->currentAccount = $user;

        if ($user) {
            // 1. Muat folder dari database sesuai yang dibuat pengguna (tanpa folder default)
            $this->customFolders = MailboxFolder::where('virtual_user_id', $user->id)->pluck('name')->toArray();

            // 2. Sinkronkan email riil dari harddisk VPS (/var/vmail) secara teratur (dibatasi 1x per 2 menit agar loading secepat kilat)
            $lastSyncKey = "maildir_last_sync_{$user->id}";
            if (!cache()->has($lastSyncKey)) {
                $this->syncFromMaildir($user);
                cache()->put($lastSyncKey, true, now()->addMinutes(2));
            }

            // 3. Muat daftar alias yang diarahkan ke akun mailbox ini
            $this->loadAvailableAliases($user);

            // 4. Sinkronkan kontak dari riwayat komunikasi
            $this->syncContactsFromHistory();
        }
    }

    protected function loadAvailableAliases(?VirtualUser $user)
    {
        if (!$user) return;
        $this->availableAliases = \App\Models\VirtualAlias::where('is_active', true)
            ->where(function($q) use ($user) {
                $q->where('destination_email', $user->email)
                  ->orWhere('destination_email', 'like', "%{$user->email}%");
            })
            ->pluck('source_email')
            ->unique()
            ->values()
            ->toArray();

        if (empty($this->composeFrom)) {
            $this->composeFrom = $user->email;
        }
        if (empty($this->quickReplyFrom)) {
            $this->quickReplyFrom = $user->email;
        }
    }

    public function refreshInbox()
    {
        $user = $this->getAccount();
        if ($user) {
            $syncedCount = $this->syncFromMaildir($user);
            $actualBytes = $this->calculateActualEmailsBytes();
            $user->syncMaildirDiskUsage($actualBytes);

            if ($syncedCount > 0) {
                session()->flash('webmail_msg', "Berhasil menarik {$syncedCount} email baru dari Dovecot Maildir!");
            } else {
                session()->flash('webmail_msg', 'Kotak masuk sudah dalam kondisi terbaru.');
            }
        }
    }

    /**
     * Sinkronisasi file email fisik dari Maildir Dovecot (/var/vmail/domain/user/new & cur)
     */
    protected function syncFromMaildir(VirtualUser $user): int
    {
        $domain = $user->domain ? $user->domain->name : null;
        if (!$domain && str_contains($user->email, '@')) {
            $parts = explode('@', $user->email);
            $domain = $parts[1];
        }

        $localPart = explode('@', $user->email)[0];
        $maildirBase = '/var/vmail/' . ($domain ? "{$domain}/{$localPart}" : ltrim($user->maildir_path, '/'));

        if (!is_dir($maildirBase)) {
            return 0;
        }

        $subdirs = ['new', 'cur'];
        $synced = 0;

        foreach ($subdirs as $sub) {
            $dirPath = "{$maildirBase}/{$sub}";
            if (!is_dir($dirPath)) continue;

            $files = @scandir($dirPath);
            if (!$files) continue;

            foreach ($files as $file) {
                if ($file === '.' || $file === '..' || str_starts_with($file, '.')) continue;

                $fullFile = "{$dirPath}/{$file}";
                if (!is_file($fullFile) || !is_readable($fullFile)) continue;

                // Gunakan hash nama file unik sebagai identitas
                $fileKey = md5("{$user->id}_{$file}");
                $existingRecord = MailboxEmail::where('virtual_user_id', $user->id)
                    ->where('body', 'like', "%[UID:{$fileKey}]%")
                    ->first();

                $rawContent = @file_get_contents($fullFile);
                if (!$rawContent) continue;

                $parsed = $this->parseRawRfc822Email($rawContent);

                if ($existingRecord) {
                    // Jika email sudah pernah disimpan tetapi masih memuat teks boundary berantakan, perbarui sekarang
                    if (str_contains($existingRecord->body, 'Content-Type: text/')) {
                        $existingRecord->update([
                            'from_name' => $parsed['from_name'] ?: $existingRecord->from_name,
                            'from_email' => $parsed['from_email'] ?: $existingRecord->from_email,
                            'subject' => $parsed['subject'] ?: $existingRecord->subject,
                            'body' => $parsed['body'] . "\n\n<!-- [UID:{$fileKey}] -->",
                        ]);
                    }
                    continue;
                }

                MailboxEmail::create([
                    'virtual_user_id' => $user->id,
                    'folder' => 'inbox',
                    'from_name' => $parsed['from_name'] ?: 'Sender',
                    'from_email' => $parsed['from_email'] ?: 'unknown@domain.com',
                    'to' => $user->email,
                    'subject' => $parsed['subject'] ?: '(Tanpa Subjek)',
                    'date_human' => $parsed['date'] ?: now()->format('d M, H:i'),
                    'is_read' => ($sub === 'cur'),
                    'is_starred' => false,
                    'body' => $parsed['body'] . "\n\n<!-- [UID:{$fileKey}] -->",
                    'attachments' => $parsed['attachments'],
                ]);

                // Otomatis simpan pengirim ke kontak
                if (!empty($parsed['from_email']) && $parsed['from_email'] !== 'unknown@domain.com') {
                    MailboxContact::recordCommunication($user->id, $parsed['from_email'], $parsed['from_name']);
                }

                $synced++;
            }
        }

        return $synced;
    }

    /**
     * Parser sederhana email mentah RFC 822 (headers + body)
     */
    protected function parseRawRfc822Email(string $raw, ?int $userId = null): array
    {
        $parts = explode("\r\n\r\n", $raw, 2);
        if (count($parts) < 2) {
            $parts = explode("\n\n", $raw, 2);
        }

        $headerStr = $parts[0] ?? '';
        $body = $parts[1] ?? '';

        $fromName = '';
        $fromEmail = '';
        $toEmail = '';
        $subject = '(Tanpa Subjek)';
        $date = '';

        $unfoldedHeaderStr = preg_replace('/\r?\n[ \t]+/', ' ', $headerStr);
        $lines = preg_split('/\r?\n/', $unfoldedHeaderStr);
        $headers = [];

        foreach ($lines as $line) {
            if (preg_match('/^([a-zA-Z0-9\-]+):\s*(.*)$/', $line, $matches)) {
                $currentKey = strtolower($matches[1]);
                $headers[$currentKey] = trim($matches[2]);
            }
        }

        if (!empty($headers['from'])) {
            $fromRaw = $headers['from'];
            if (preg_match('/^(.*?)\s*<([^>]+)>/', $fromRaw, $m)) {
                $fromName = $this->decodeMimeHeader(trim(trim($m[1]), '"\''));
                $fromEmail = strtolower(trim($m[2]));
            } else {
                $fromEmail = strtolower(trim($fromRaw));
                $fromName = $fromEmail;
            }
        }

        if (!empty($headers['to'])) {
            if (preg_match('/<([^>]+)>/', $headers['to'], $m)) {
                $toEmail = strtolower(trim($m[1]));
            } else {
                $toEmail = strtolower(trim($headers['to']));
            }
        }

        if (!empty($headers['subject'])) {
            $subject = $this->decodeMimeHeader($headers['subject']);
        }

        if (!empty($headers['date'])) {
            try {
                $date = \Carbon\Carbon::parse($headers['date'])->format('d M, H:i');
            } catch (\Throwable $e) {
                $date = now()->format('d M, H:i');
            }
        }

        // Tangani MIME Multipart & Ekstraksi File Lampiran Fisik ke Folder Storage
        $extracted = $this->extractCleanMimeAndAttachments($headerStr, $body, $userId);

        return [
            'from_name' => $fromName,
            'from_email' => $fromEmail,
            'to' => $toEmail,
            'subject' => $subject,
            'date' => $date,
            'body' => $extracted['body'],
            'attachments' => $extracted['attachments'],
        ];
    }

    /**
     * Ekstraksi teks/HTML dan simpan file lampiran fisik ke folder storage (hanya metadata di database)
     */
    protected function extractCleanMimeAndAttachments(string $headers, string $body, ?int $userId = null): array
    {
        $attachments = [];
        $htmlPart = null;
        $textPart = null;

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

                $filename = null;
                // RFC 2231 / RFC 5987: filename*=UTF-8''...
                if (preg_match('/filename\*=(?:[a-zA-Z0-9_\-]+\'\')?([^;\r\n]+)/i', $partHeader, $m)) {
                    $filename = urldecode(trim(trim($m[1]), '"\''));
                } elseif (preg_match('/filename=["\']?([^"\'\r\n;]+)["\']?/i', $partHeader, $m)) {
                    $filename = trim($m[1]);
                } elseif (preg_match('/name=["\']?([^"\'\r\n;]+)["\']?/i', $partHeader, $m)) {
                    $filename = trim($m[1]);
                }

                if ($filename) {
                    $filename = basename($this->decodeMimeHeader($filename));
                    $decodedFile = $partContent;
                    if (stripos($partHeader, 'Content-Transfer-Encoding: base64') !== false) {
                        $cleanBase = preg_replace('/\s+/', '', $partContent);
                        $decoded = base64_decode($cleanBase);
                        if ($decoded !== false) $decodedFile = $decoded;
                    } elseif (stripos($partHeader, 'Content-Transfer-Encoding: quoted-printable') !== false) {
                        $decodedFile = quoted_printable_decode($partContent);
                    }

                    if ($decodedFile !== false && strlen($decodedFile) > 0) {
                        $uFolder = $userId ? "attachments/{$userId}" : 'attachments';
                        \Illuminate\Support\Facades\Storage::disk('public')->makeDirectory($uFolder);

                        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION) ?: 'dat');
                        $safeName = time() . '_' . bin2hex(random_bytes(4)) . '_' . preg_replace('/[^a-zA-Z0-9\._-]/', '_', $filename);
                        $storagePath = $uFolder . '/' . $safeName;

                        \Illuminate\Support\Facades\Storage::disk('public')->put($storagePath, $decodedFile);

                        $sizeBytes = strlen($decodedFile);
                        $sizeKb = round($sizeBytes / 1024, 1);
                        $formattedSize = $sizeKb > 1024 ? round($sizeKb / 1024, 1) . ' MB' : $sizeKb . ' KB';

                        $attachments[] = [
                            'name' => $filename,
                            'size' => $formattedSize,
                            'ext'  => $ext,
                            'path' => $storagePath,
                            'url'  => \Illuminate\Support\Facades\Storage::url($storagePath),
                            'bytes' => $sizeBytes,
                        ];
                    }
                    continue;
                }

                // Cek recursive multipart
                if (preg_match('/boundary=["\']?([^"\';\r\n]+)["\']?/i', $partHeader, $innerBMatch)) {
                    $innerRes = $this->extractCleanMimeAndAttachments($partHeader, $partContent, $userId);
                    if (!empty($innerRes['body'])) $htmlPart = $innerRes['body'];
                    if (!empty($innerRes['attachments'])) $attachments = array_merge($attachments, $innerRes['attachments']);
                    continue;
                }

                $decodedPart = $partContent;
                if (stripos($partHeader, 'base64') !== false) {
                    $cleanBase = preg_replace('/\s+/', '', $partContent);
                    $decoded = base64_decode($cleanBase);
                    if ($decoded !== false) $decodedPart = $decoded;
                } elseif (stripos($partHeader, 'quoted-printable') !== false) {
                    $decodedPart = quoted_printable_decode($partContent);
                }

                if (stripos($partHeader, 'text/html') !== false) {
                    $htmlPart = trim($decodedPart);
                } elseif (stripos($partHeader, 'text/plain') !== false && empty($textPart)) {
                    $textPart = trim($decodedPart);
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
     * Ekstraksi teks atau HTML bersih dari pesan MIME multipart
     */
    protected function extractCleanMimeBody(string $headers, string $body): string
    {
        $boundary = null;

        // 1. Cari MIME boundary dari header
        if (preg_match('/boundary=["\']?([^"\';\r\n]+)["\']?/i', $headers, $bMatch)) {
            $boundary = trim($bMatch[1]);
        } 
        // 2. Fallback: Cari pola boundary langsung di dalam body jika header terpotong (misal: --000000000000d133a0065cf19036)
        elseif (preg_match('/--([a-zA-Z0-9_\-\.\/=]{15,})/m', $body, $bMatch)) {
            $boundary = trim($bMatch[1]);
        }

        if ($boundary) {
            $delimiter = '--' . $boundary;
            $parts = explode($delimiter, $body);

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

                // Handle Transfer-Encoding: base64
                if (stripos($subHeader, 'Content-Transfer-Encoding: base64') !== false) {
                    $subContent = base64_decode(preg_replace('/\s+/', '', $subContent)) ?: $subContent;
                }
                // Handle Transfer-Encoding: quoted-printable
                elseif (stripos($subHeader, 'Content-Transfer-Encoding: quoted-printable') !== false) {
                    $subContent = quoted_printable_decode($subContent);
                }

                if (stripos($subHeader, 'text/html') !== false) {
                    $htmlPart = trim($subContent);
                } elseif (stripos($subHeader, 'text/plain') !== false) {
                    $textPart = trim($subContent);
                } elseif (!$htmlPart && !$textPart && !empty($subContent)) {
                    $textPart = trim($subContent);
                }
            }

            // Prioritaskan HTML part jika ada, atau fallback ke teks biasa
            if (!empty($htmlPart)) {
                return $htmlPart;
            }
            if (!empty($textPart)) {
                return $textPart;
            }
        }

        // 3. Jika bukan multipart ber-boundary, periksa encoding biasa
        if (stripos($headers, 'Content-Transfer-Encoding: base64') !== false) {
            $decoded = base64_decode(preg_replace('/\s+/', '', $body));
            if ($decoded) return $decoded;
        } elseif (stripos($headers, 'Content-Transfer-Encoding: quoted-printable') !== false) {
            return quoted_printable_decode($body);
        }

        // 4. Jika masih tersisa pola boundary di dalam body, bersihkan dengan regex
        $cleaned = preg_replace('/--[a-zA-Z0-9_\-\.\/=]{15,}--?/s', '', $body);
        $cleaned = preg_replace('/Content-Type:\s*[^;\r\n]+(;\s*charset=[^;\r\n]+)?/i', '', $cleaned);
        $cleaned = preg_replace('/Content-Transfer-Encoding:\s*[^\r\n]+/i', '', $cleaned);

        return trim($cleaned) ?: trim($body);
    }

    protected function seedInitialEmailsIfEmpty()
    {
        $user = $this->getAccount();
        if (!$user) return;

        if (MailboxEmail::where('virtual_user_id', $user->id)->count() === 0) {
            $seeds = [
                [
                    'folder' => 'inbox',
                    'from_name' => 'Dwifa Ardianty',
                    'from_email' => 'dwifa.ardianty@ioh.co.id',
                    'to' => 'admin@perusahaan.net.id',
                    'subject' => 'FAB , Kontrak dan T&C PT Infra Digital Solusindo',
                    'date_human' => 'Hari ini, 14:30',
                    'is_read' => true,
                    'is_starred' => true,
                    'body' => "Dear Bg Agiel ,\n\nBerikut di lampirkan FAB , Kontrak dan T&C PT Infra Digital Solusindo untuk ditinjau dan ditandatangani.\nMohon konfirmasi jika ada dokumen yang perlu direvisi kembali.\n\nTerima kasih atas kerja samanya.\n\nSalam hangat,\nDwifa Ardianty\nPT Indosat Ooredoo Hutchison",
                    'attachments' => [
                        ['name' => '220124 -...OH.pdf', 'size' => '720 KB', 'type' => 'pdf'],
                        ['name' => 'FAB 100 M...DO.pdf', 'size' => '4 MB', 'type' => 'pdf'],
                        ['name' => 'KONTRAK P...DO.pdf', 'size' => '1 MB', 'type' => 'pdf'],
                    ],
                ],
                [
                    'folder' => 'inbox',
                    'from_name' => 'Mail Delivery System',
                    'from_email' => 'postmaster@perusahaan.net.id',
                    'to' => 'admin@perusahaan.net.id',
                    'subject' => 'Selamat Datang di Mail Server Mandiri Anda',
                    'date_human' => 'Hari ini, 11:15',
                    'is_read' => false,
                    'is_starred' => false,
                    'body' => "Halo Administrator,\n\nMail Server Postfix & Dovecot Anda telah tersinkronisasi sempurna dengan Web Portal Laravel.\n\nInformasi Konfigurasi Mailbox:\n• Protokol IMAP: mail.domain.net.id (Port 993 - SSL/TLS)\n• Protokol SMTP: mail.domain.net.id (Port 587 - STARTTLS)\n• Autentikasi: Menggunakan username email lengkap dan password database virtual_users.\n\nSalam hangat,\nTim Postmaster MailIDS",
                    'attachments' => [
                        ['name' => 'Panduan_IMAP_SMTP_Server.pdf', 'size' => '850 KB', 'type' => 'pdf'],
                    ],
                ],
                [
                    'folder' => 'inbox',
                    'from_name' => 'Siti Aminah (Finance)',
                    'from_email' => 'siti.aminah@perusahaan.net.id',
                    'to' => 'admin@perusahaan.net.id',
                    'subject' => 'Laporan Penggunaan Kuota Storage Email Bulan Ini',
                    'date_human' => 'Kemarin, 16:45',
                    'is_read' => true,
                    'is_starred' => false,
                    'body' => "Yth. Administrator,\n\nMohon diperiksa kuota penyimpanan mailbox divisi finance sudah mencapai 85%. Apakah bisa dilakukan penambahan alokasi storage melalui panel portal admin?\n\nTerima kasih,\nSiti Aminah",
                    'attachments' => [
                        ['name' => 'Laporan_Finance_Storage_Q3.pdf', 'size' => '1.2 MB', 'type' => 'pdf'],
                    ],
                ],
                [
                    'folder' => 'sent',
                    'from_name' => 'Administrator',
                    'from_email' => 'admin@perusahaan.net.id',
                    'to' => 'budi.santoso@perusahaan.net.id',
                    'subject' => 'Instruksi Penggunaan Webmail & Klien Thunderbird',
                    'date_human' => 'Kemarin, 09:10',
                    'is_read' => true,
                    'is_starred' => false,
                    'body' => "Budi,\n\nBerikut panduan login ke mailbox perusahaan via Webmail Portal atau setup di Thunderbird / Outlook mobile.\nPastikan gunakan SSL port 993 untuk IMAP.\n\nAdmin",
                    'attachments' => [],
                ],
                [
                    'folder' => 'spam',
                    'from_name' => 'Crypto Winner Giveaway',
                    'from_email' => 'claims@super-btc-lottery.xyz',
                    'to' => 'admin@perusahaan.net.id',
                    'subject' => 'Pemberitahuan: Anda Memenangkan 0.5 BTC Giveaway!',
                    'date_human' => '2 hari lalu',
                    'is_read' => false,
                    'is_starred' => false,
                    'spam_reason' => 'Pesan ini dilaporkan sebagai spam oleh filter keamanan kami karena domain pengirim (super-btc-lottery.xyz) tidak memiliki catatan autentikasi SPF/DKIM yang valid, serta berisi pola pancingan informasi sensitif (Phishing/Scam).',
                    'spam_score' => 8.7,
                    'body' => "Selamat! Alamat email Anda telah terpilih secara acak dalam program reward tahunan kami. Segera klaim hadiah Bitcoin Anda dengan mengklik tautan di bawah ini.\n\nCatatan: Tautan ini hanya berlaku 24 jam.",
                    'attachments' => [],
                ],
                [
                    'folder' => 'drafts',
                    'from_name' => 'Administrator',
                    'from_email' => 'admin@perusahaan.net.id',
                    'to' => 'partner@vendor-cloud.com',
                    'subject' => 'Draft Penawaran Kolaborasi Server Email Q4',
                    'date_human' => 'Hari ini, 10:00',
                    'is_read' => true,
                    'is_starred' => false,
                    'body' => "Yth. Tim Vendor Cloud,\n\nKami tertarik untuk mendiskusikan integrasi relay server untuk meningkatkan kapasitas pengiriman bulanan. Berikut beberapa parameter kebutuhan yang sedang kami susun...\n\n(Draf belum selesai)",
                    'attachments' => [],
                ],
            ];

            foreach ($seeds as $seed) {
                MailboxEmail::create(array_merge($seed, [
                    'virtual_user_id' => $user->id,
                ]));
            }
        }
    }

    protected function calculateActualEmailsBytes(): int
    {
        $user = $this->getAccount();
        if (!$user) return 0;

        $emails = MailboxEmail::where('virtual_user_id', $user->id)->get(['from_email', 'to', 'from_name', 'subject', 'body', 'attachments']);
        $totalBytes = 0;
        foreach ($emails as $email) {
            $mimeHeaderOverhead = 1200;
            $bodyBytes = strlen($email->body ?? '');
            $subjectBytes = strlen($email->subject ?? '');
            $headersBytes = strlen(($email->from_email ?? '') . ($email->to ?? '') . ($email->from_name ?? ''));
            $attachmentsCount = count($email->attachments ?? []);
            $attachmentsBytes = $attachmentsCount * 125000;

            $totalBytes += ($mimeHeaderOverhead + $bodyBytes + $subjectBytes + $headersBytes + $attachmentsBytes);
        }

        return $totalBytes;
    }

    public function selectFolder($folder)
    {
        $this->activeFolder = $folder;
        $this->viewMode = 'list';
        $this->selectedEmailId = null;
        $this->selectedIds = [];
    }

    public function backToList()
    {
        $this->viewMode = 'list';
    }

    public function setFilter($filter)
    {
        $this->activeFilter = $filter;
    }

    public function createFolder()
    {
        $this->validate([
            'newFolderName' => 'required|string|min:2|max:30',
        ]);

        $user = $this->getAccount();
        $folderClean = trim($this->newFolderName);
        if (!in_array($folderClean, $this->customFolders)) {
            $this->customFolders[] = $folderClean;
            if ($user) {
                MailboxFolder::firstOrCreate([
                    'virtual_user_id' => $user->id,
                    'name' => $folderClean,
                ]);
            }
            $this->selectFolder($folderClean);
            session()->flash('webmail_msg', "Folder baru '{$folderClean}' berhasil dibuat!");
        }

        $this->newFolderName = '';
        $this->showCreateFolderModal = false;
    }

    public function deleteFolder($folderName)
    {
        $user = $this->getAccount();
        if ($user) {
            MailboxEmail::where('virtual_user_id', $user->id)
                ->where('folder', $folderName)
                ->update(['folder' => 'inbox']);

            MailboxFolder::where('virtual_user_id', $user->id)
                ->where('name', $folderName)
                ->delete();
        }

        $this->customFolders = array_values(array_diff($this->customFolders, [$folderName]));

        if ($this->activeFolder === $folderName) {
            $this->selectFolder('inbox');
        }

        session()->flash('webmail_msg', "Folder '{$folderName}' berhasil dihapus. Isi email telah dipindahkan ke Kotak Masuk.");
    }

    public function moveToFolder($targetFolder)
    {
        $user = $this->getAccount();
        if ($user && $this->selectedEmailId) {
            MailboxEmail::where('virtual_user_id', $user->id)
                ->where('id', $this->selectedEmailId)
                ->update(['folder' => $targetFolder]);
        }
        $this->selectFolder($targetFolder);
        session()->flash('webmail_msg', "Pesan dipindahkan ke folder {$targetFolder}.");
    }

    public function markAsSpam()
    {
        $this->moveToFolder('spam');
    }

    public function markNotSpam()
    {
        $this->moveToFolder('inbox');
    }

    public function toggleSelectAll()
    {
        $user = $this->getAccount();
        if (!$user) return;

        $currentIds = MailboxEmail::where('virtual_user_id', $user->id)
            ->where('folder', $this->activeFolder)
            ->pluck('id')
            ->map(fn($id) => (string)$id)
            ->toArray();

        if (count($this->selectedIds) >= count($currentIds) && count($currentIds) > 0) {
            $this->selectedIds = [];
        } else {
            $this->selectedIds = $currentIds;
        }
    }

    public function deleteSingleEmail($id)
    {
        $user = $this->getAccount();
        if ($user && $id) {
            $item = MailboxEmail::where('virtual_user_id', $user->id)->find($id);
            if ($item) {
                if ($item->folder === 'trash' || $this->activeFolder === 'trash') {
                    $this->deletePhysicalMailFile($user, $item);
                    $item->delete();
                    session()->flash('webmail_msg', 'Pesan berhasil dihapus secara permanen.');
                } else {
                    $item->update(['folder' => 'trash']);
                    session()->flash('webmail_msg', 'Pesan dipindahkan ke Sampah.');
                }
            }
        }
        $this->selectFolder($this->activeFolder);
    }

    public function deleteSelectedMultiple()
    {
        if (empty($this->selectedIds)) return;
        $user = $this->getAccount();
        if ($user) {
            if ($this->activeFolder === 'trash') {
                $items = MailboxEmail::where('virtual_user_id', $user->id)
                    ->whereIn('id', $this->selectedIds)
                    ->get();
                foreach ($items as $it) {
                    $this->deletePhysicalMailFile($user, $it);
                    $it->delete();
                }
                session()->flash('webmail_msg', count($this->selectedIds) . ' pesan berhasil dihapus secara permanen.');
            } else {
                MailboxEmail::where('virtual_user_id', $user->id)
                    ->whereIn('id', $this->selectedIds)
                    ->update(['folder' => 'trash']);
                session()->flash('webmail_msg', count($this->selectedIds) . ' pesan terpilih dipindahkan ke Sampah.');
            }
        }
        $this->selectedIds = [];
    }

    public function markMultipleRead()
    {
        if (empty($this->selectedIds)) return;
        $user = $this->getAccount();
        if ($user) {
            MailboxEmail::where('virtual_user_id', $user->id)
                ->whereIn('id', $this->selectedIds)
                ->update(['is_read' => true]);
        }
        $this->selectedIds = [];
    }

    public function selectEmail($id)
    {
        $this->selectedEmailId = $id;
        $this->viewMode = 'detail';
        $user = $this->getAccount();
        if ($user) {
            MailboxEmail::where('id', $id)
                ->where('virtual_user_id', $user->id)
                ->update(['is_read' => true]);
        }
    }

    public function toggleReadStatus($id)
    {
        $user = $this->getAccount();
        if ($user) {
            $email = MailboxEmail::where('id', $id)->where('virtual_user_id', $user->id)->first();
            if ($email) {
                $email->update(['is_read' => !$email->is_read]);
            }
        }
    }

    public function toggleStar($id)
    {
        $user = $this->getAccount();
        if ($user) {
            $email = MailboxEmail::where('id', $id)->where('virtual_user_id', $user->id)->first();
            if ($email) {
                $email->update(['is_starred' => !$email->is_starred]);
            }
        }
    }

    public function replyEmail()
    {
        $user = $this->getAccount();
        $selected = $user ? MailboxEmail::where('virtual_user_id', $user->id)->find($this->selectedEmailId) : null;
        if ($selected) {
            $this->inlineReplyMode = 'reply';
            $this->showInlineReply = true;
            $this->quickReplyTo = $selected->from_email;
            $this->quickReplySubject = 'Re: ' . preg_replace('/^Re:\s*/i', '', $selected->subject);
            $this->quickReplyText = '';
            $this->showInlineCcBcc = false;
        }
    }

    public function forwardEmail()
    {
        $user = $this->getAccount();
        $selected = $user ? MailboxEmail::where('virtual_user_id', $user->id)->find($this->selectedEmailId) : null;
        if ($selected) {
            $this->inlineReplyMode = 'forward';
            $this->showInlineReply = true;
            $this->quickReplyTo = '';
            $this->quickReplySubject = 'Fwd: ' . preg_replace('/^Fwd:\s*/i', '', $selected->subject);
            $this->showInlineCcBcc = false;
            
            // Format teks forwarded persis seperti tampilan Hostinger
            $this->quickReplyText = "\n\n---------- Forwarded message ---------\n" .
                "From: " . $selected->from_name . " <" . $selected->from_email . ">\n" .
                "Date: " . ($selected->date_human ?: $selected->created_at->format('d M, H:i')) . "\n" .
                "Subject: " . $selected->subject . "\n" .
                "To: " . ($selected->to ?? 'admin@ids.net.id') . "\n\n" .
                $selected->body;
        }
    }

    public function closeInlineReply()
    {
        $this->showInlineReply = false;
        $this->quickReplyTo = '';
        $this->quickReplyCc = '';
        $this->quickReplyBcc = '';
        $this->quickReplySubject = '';
        $this->showInlineCcBcc = false;
        $this->quickReplyText = '';
        $this->quickReplyAttachments = [];
    }

    public function removeAttachment($index)
    {
        if (isset($this->attachments[$index])) {
            unset($this->attachments[$index]);
            $this->attachments = array_values($this->attachments);
        }
    }

    public function removeQuickReplyAttachment($index)
    {
        if (isset($this->quickReplyAttachments[$index])) {
            unset($this->quickReplyAttachments[$index]);
            $this->quickReplyAttachments = array_values($this->quickReplyAttachments);
        }
    }

    /**
     * Hapus file email fisik dari Maildir Dovecot (/var/vmail/domain/user/{new,cur})
     * agar saat reload / syncFromMaildir email tidak terimpor ulang.
     */
    protected function deletePhysicalMailFile($user, $item): void
    {
        if (!$user || !$item) return;

        $targetKey = null;
        if (!empty($item->body) && preg_match('/\[UID:([a-f0-9]{32})\]/i', $item->body, $matches)) {
            $targetKey = $matches[1];
        }

        $parts = explode('@', $user->email);
        $localPart = $parts[0] ?? '';
        $domain = $parts[1] ?? '';
        $maildirBase = '/var/vmail/' . ($domain ? "{$domain}/{$localPart}" : ltrim($user->maildir_path, '/'));

        if (!is_dir($maildirBase)) return;

        $subdirs = ['new', 'cur'];
        foreach ($subdirs as $sub) {
            $dirPath = "{$maildirBase}/{$sub}";
            if (!is_dir($dirPath)) continue;

            $files = @scandir($dirPath);
            if (!$files) continue;

            foreach ($files as $file) {
                if ($file === '.' || $file === '..' || str_starts_with($file, '.')) continue;
                $fullFile = "{$dirPath}/{$file}";

                if ($targetKey) {
                    $fileKey = md5("{$user->id}_{$file}");
                    if ($fileKey === $targetKey) {
                        @unlink($fullFile);
                        break 2;
                    }
                }
            }
        }
    }

    public function deleteSelectedEmail()
    {
        $user = $this->getAccount();
        if ($user && $this->selectedEmailId) {
            $item = MailboxEmail::where('virtual_user_id', $user->id)->find($this->selectedEmailId);
            if ($item) {
                if ($item->folder === 'trash' || $this->activeFolder === 'trash') {
                    $this->deletePhysicalMailFile($user, $item);
                    $item->delete();
                    session()->flash('webmail_msg', 'Pesan berhasil dihapus secara permanen.');
                } else {
                    $item->update(['folder' => 'trash']);
                    session()->flash('webmail_msg', 'Pesan dipindahkan ke Sampah.');
                }
            }
        }
        $this->selectedEmailId = null;
        $this->viewMode = 'list';
        $this->selectFolder($this->activeFolder);
    }

    public function restoreFromTrash()
    {
        $user = $this->getAccount();
        if ($user && $this->selectedEmailId) {
            MailboxEmail::where('virtual_user_id', $user->id)
                ->where('id', $this->selectedEmailId)
                ->update(['folder' => 'inbox']);
        }
        $this->selectFolder('inbox');
        session()->flash('webmail_msg', 'Pesan berhasil dipulihkan kembali ke Kotak Masuk.');
    }

    public function emptyTrash()
    {
        $user = $this->getAccount();
        if ($user) {
            $trashItems = MailboxEmail::where('virtual_user_id', $user->id)
                ->where('folder', 'trash')
                ->get();

            foreach ($trashItems as $tItem) {
                $this->deletePhysicalMailFile($user, $tItem);
                $tItem->delete();
            }
        }
        $this->selectedEmailId = null;
        $this->selectFolder('trash');
        session()->flash('webmail_msg', 'Folder Sampah telah dikosongkan secara permanen.');
    }

    public function emptySpam()
    {
        $user = $this->getAccount();
        if ($user) {
            MailboxEmail::where('virtual_user_id', $user->id)
                ->where('folder', 'spam')
                ->delete();
        }
        $this->selectFolder('spam');
        session()->flash('webmail_msg', 'Semua pesan spam telah dihapus secara permanen.');
    }

    public function closeComposeModal()
    {
        // Fitur Gmail: Jika ada teks yang sudah diketik, simpan otomatis ke Drafts
        if (!empty(trim($this->composeSubject)) || !empty(trim($this->composeBody)) || !empty(trim($this->composeTo))) {
            $this->saveDraftNow(true);
        } else {
            $this->resetComposeForm();
        }
        $this->showComposeModal = false;
    }

    public function discardDraft()
    {
        $user = $this->getAccount();
        if ($this->editingDraftId && $user) {
            MailboxEmail::where('virtual_user_id', $user->id)
                ->where('id', $this->editingDraftId)
                ->delete();
            session()->flash('webmail_msg', 'Draf pesan telah dibuang.');
        }
        $this->resetComposeForm();
        $this->showComposeModal = false;
    }

    public function saveDraftNow($silent = false)
    {
        $user = $this->getAccount();
        if (!$user) return;

        $savedAttachments = [];
        if (!empty($this->attachments)) {
            $uFolder = "attachments/{$user->id}";
            \Illuminate\Support\Facades\Storage::disk('public')->makeDirectory($uFolder);
            foreach ($this->attachments as $file) {
                if (is_object($file) && method_exists($file, 'getClientOriginalName')) {
                    $origName = $file->getClientOriginalName();
                    $ext = strtolower($file->getClientOriginalExtension() ?: 'dat');
                    $sizeBytes = $file->getSize() ?: 0;
                    $sizeKb = round($sizeBytes / 1024, 1);
                    $formattedSize = $sizeKb > 1024 ? round($sizeKb / 1024, 1) . ' MB' : $sizeKb . ' KB';
                    $safeName = time() . '_' . bin2hex(random_bytes(4)) . '_' . preg_replace('/[^a-zA-Z0-9\._-]/', '_', $origName);
                    $storagePath = $file->storeAs($uFolder, $safeName, 'public');

                    $savedAttachments[] = [
                        'name' => $origName,
                        'size' => $formattedSize,
                        'ext'  => $ext,
                        'path' => $storagePath,
                        'url'  => \Illuminate\Support\Facades\Storage::url($storagePath),
                        'bytes' => $sizeBytes,
                    ];
                } elseif (is_array($file)) {
                    $savedAttachments[] = $file;
                }
            }
        }

        $subject = trim($this->composeSubject) ?: '(Tanpa Subjek)';
        $body = $this->composeBody ?: '';

        if ($this->editingDraftId) {
            MailboxEmail::where('virtual_user_id', $user->id)
                ->where('id', $this->editingDraftId)
                ->update([
                    'to' => $this->composeTo,
                    'subject' => $subject,
                    'body' => $body,
                    'date_human' => 'Hari ini, ' . date('H:i'),
                    'attachments' => $savedAttachments,
                ]);
        } else {
            $created = MailboxEmail::create([
                'virtual_user_id' => $user->id,
                'folder' => 'drafts',
                'from_name' => $user->name ?: 'Administrator',
                'from_email' => $user->email ?: 'admin@perusahaan.net.id',
                'to' => $this->composeTo ?: '(Belum ada penerima)',
                'subject' => $subject,
                'date_human' => 'Hari ini, ' . date('H:i'),
                'is_read' => true,
                'is_starred' => false,
                'body' => $body,
                'attachments' => $savedAttachments,
            ]);
            $this->editingDraftId = $created->id;
        }

        if (!$silent) {
            session()->flash('webmail_msg', 'Draf berhasil disimpan.');
        }
    }

    public function openDraft($id)
    {
        $user = $this->getAccount();
        $draft = $user ? MailboxEmail::where('virtual_user_id', $user->id)->find($id) : null;
        if ($draft && $draft->folder === 'drafts') {
            $this->editingDraftId = $draft->id;
            $this->composeTo = $draft->to === '(Belum ada penerima)' ? '' : $draft->to;
            $this->composeSubject = $draft->subject === '(Tanpa Subjek)' ? '' : $draft->subject;
            $this->composeBody = $draft->body;
            $this->showComposeModal = true;
        }
    }

    protected function resetComposeForm()
    {
        $this->reset(['composeTo', 'composeCc', 'composeBcc', 'composeSubject', 'composeBody', 'attachments', 'editingDraftId']);
    }

    public function sendEmail()
    {
        $this->validate([
            'composeTo' => 'required|email',
            'composeCc' => 'nullable|email',
            'composeBcc' => 'nullable|email',
            'composeSubject' => 'required|string|max:255',
            'composeBody' => 'required|string',
        ]);

        $user = $this->getAccount();
        if (!$user) return;

        // Hapus draft lama jika email ini dikirim dari draf yang diedit
        if ($this->editingDraftId) {
            MailboxEmail::where('virtual_user_id', $user->id)
                ->where('id', $this->editingDraftId)
                ->delete();
        }

        $savedAttachments = [];
        if (!empty($this->attachments)) {
            $uFolder = "attachments/{$user->id}";
            \Illuminate\Support\Facades\Storage::disk('public')->makeDirectory($uFolder);
            foreach ($this->attachments as $file) {
                if (is_object($file) && method_exists($file, 'getClientOriginalName')) {
                    $origName = $file->getClientOriginalName();
                    $ext = strtolower($file->getClientOriginalExtension() ?: 'dat');
                    $sizeBytes = $file->getSize() ?: 0;
                    $sizeKb = round($sizeBytes / 1024, 1);
                    $formattedSize = $sizeKb > 1024 ? round($sizeKb / 1024, 1) . ' MB' : $sizeKb . ' KB';
                    $safeName = time() . '_' . bin2hex(random_bytes(4)) . '_' . preg_replace('/[^a-zA-Z0-9\._-]/', '_', $origName);
                    $storagePath = $file->storeAs($uFolder, $safeName, 'public');

                    $savedAttachments[] = [
                        'name' => $origName,
                        'size' => $formattedSize,
                        'ext'  => $ext,
                        'path' => $storagePath,
                        'url'  => \Illuminate\Support\Facades\Storage::url($storagePath),
                        'bytes' => $sizeBytes,
                    ];
                } elseif (is_array($file)) {
                    $savedAttachments[] = $file;
                }
            }
        }

        // Kirim fisik email keluar via Postfix SMTP / Sendmail (mendukung alias sender)
        $fromEmail = !empty($this->composeFrom) ? $this->composeFrom : ($user->email ?: 'admin@perusahaan.net.id');
        $fromName = $user->name ?: 'Administrator';
        \App\Services\MailService::sendOutboundMail(
            $fromEmail,
            $fromName,
            $this->composeTo,
            $this->composeSubject,
            $this->composeBody,
            $savedAttachments
        );

        MailboxEmail::create([
            'virtual_user_id' => $user->id,
            'folder' => 'sent',
            'from_name' => $fromName,
            'from_email' => $fromEmail,
            'to' => $this->composeTo,
            'subject' => $this->composeSubject,
            'date_human' => 'Baru saja',
            'is_read' => true,
            'is_starred' => false,
            'body' => $this->composeBody,
            'attachments' => $savedAttachments,
        ]);

        // Otomatis simpan kontak yang diajak berkomunikasi
        if (!empty($this->composeTo)) {
            MailboxContact::recordCommunication($user->id, $this->composeTo);
        }
        if (!empty($this->composeCc)) {
            MailboxContact::recordCommunication($user->id, $this->composeCc);
        }
        if (!empty($this->composeBcc)) {
            MailboxContact::recordCommunication($user->id, $this->composeBcc);
        }

        $actualBytes = $this->calculateActualEmailsBytes();
        $user->syncMaildirDiskUsage($actualBytes);

        $this->resetComposeForm();
        $this->showComposeModal = false;
        session()->flash('webmail_msg', 'Email berhasil dikirim ke penerima melalui engine Postfix!');
    }

    public function sendQuickReply()
    {
        $user = $this->getAccount();
        $selected = $user ? MailboxEmail::where('virtual_user_id', $user->id)->find($this->selectedEmailId) : null;
        if (!$selected || !$user) return;

        if ($this->inlineReplyMode === 'forward') {
            $this->validate([
                'quickReplyTo' => 'required|email',
                'quickReplyText' => 'required|string|min:2',
            ]);
            $recipient = $this->quickReplyTo;
            $subject = trim($this->quickReplySubject) ?: ('Fwd: ' . preg_replace('/^Fwd:\s*/i', '', $selected->subject));
            $flashMessage = 'Pesan berhasil diteruskan ke ' . $recipient . '!';
        } else {
            $this->validate([
                'quickReplyText' => 'required|string|min:2',
            ]);
            $recipient = $selected->from_email;
            $subject = trim($this->quickReplySubject) ?: ('Re: ' . preg_replace('/^Re:\s*/i', '', $selected->subject));
            $flashMessage = 'Balasan berhasil dikirim!';
        }

        $savedAttachments = [];
        if (!empty($this->quickReplyAttachments)) {
            $uFolder = "attachments/{$user->id}";
            \Illuminate\Support\Facades\Storage::disk('public')->makeDirectory($uFolder);
            foreach ($this->quickReplyAttachments as $file) {
                if (is_object($file) && method_exists($file, 'getClientOriginalName')) {
                    $origName = $file->getClientOriginalName();
                    $ext = strtolower($file->getClientOriginalExtension() ?: 'dat');
                    $sizeBytes = $file->getSize() ?: 0;
                    $sizeKb = round($sizeBytes / 1024, 1);
                    $formattedSize = $sizeKb > 1024 ? round($sizeKb / 1024, 1) . ' MB' : $sizeKb . ' KB';
                    $safeName = time() . '_' . bin2hex(random_bytes(4)) . '_' . preg_replace('/[^a-zA-Z0-9\._-]/', '_', $origName);
                    $storagePath = $file->storeAs($uFolder, $safeName, 'public');

                    $savedAttachments[] = [
                        'name' => $origName,
                        'size' => $formattedSize,
                        'ext'  => $ext,
                        'path' => $storagePath,
                        'url'  => \Illuminate\Support\Facades\Storage::url($storagePath),
                        'bytes' => $sizeBytes,
                    ];
                } elseif (is_array($file)) {
                    $savedAttachments[] = $file;
                }
            }
        }

        $fromEmail = !empty($this->quickReplyFrom) ? $this->quickReplyFrom : ($user->email ?: 'admin@perusahaan.net.id');
        $fromName = $user->name ?: 'Administrator';
        \App\Services\MailService::sendOutboundMail(
            $fromEmail,
            $fromName,
            $recipient,
            $subject,
            $this->quickReplyText,
            $savedAttachments
        );

        MailboxEmail::create([
            'virtual_user_id' => $user->id,
            'folder' => 'sent',
            'from_name' => $fromName,
            'from_email' => $fromEmail,
            'to' => $recipient,
            'subject' => $subject,
            'date_human' => 'Baru saja',
            'is_read' => true,
            'is_starred' => false,
            'body' => $this->quickReplyText,
            'attachments' => $savedAttachments,
        ]);

        // Otomatis simpan kontak yang dibalas / diteruskan
        if (!empty($recipient)) {
            MailboxContact::recordCommunication($user->id, $recipient);
        }

        $actualBytes = $this->calculateActualEmailsBytes();
        $user->syncMaildirDiskUsage($actualBytes);

        $this->closeInlineReply();
        session()->flash('webmail_msg', $flashMessage);
    }

    public function cleanEmailHtml(?string $rawHtml): string
    {
        if (empty($rawHtml)) return '';

        // Jika bukan HTML, kembalikan teks dengan line breaks
        if (!preg_match('/<[a-z][\s\S]*>/i', $rawHtml)) {
            return nl2br(e($rawHtml));
        }

        $html = $rawHtml;

        // 1. Ekstrak konten dalam <body> jika ada tag <body>
        if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $html, $matches)) {
            $html = $matches[1];
        }

        // 2. Hapus komentar Office/Word <!--[if ...]><![endif]--> dan <xml> tags
        $html = preg_replace('/<!--\[if\s+gte\s+mso[\s\S]*?<!\[endif\]-->/is', '', $html);
        $html = preg_replace('/<xml[\s\S]*?<\/xml>/is', '', $html);
        $html = preg_replace('/<\/?o:[a-z0-9_-]+[^>]*>/is', '', $html);
        $html = preg_replace('/<\/?w:[a-z0-9_-]+[^>]*>/is', '', $html);
        $html = preg_replace('/<\/?m:[a-z0-9_-]+[^>]*>/is', '', $html);
        $html = preg_replace('/<\/?v:[a-z0-9_-]+[^>]*>/is', '', $html);

        // 3. Hapus tag <script> atau <style> bawaan yang bisa merusak styling antarmuka webmail
        $html = preg_replace('#<script\b[^>]*>(.*?)<\/script>#is', '', $html);
        $html = preg_replace('#<style\b[^>]*>(.*?)<\/style>#is', '', $html);
        $html = preg_replace('#<link\b[^>]*>#is', '', $html);
        $html = preg_replace('#<meta\b[^>]*>#is', '', $html);

        // 4. Sanitasi atribut on* (onclick, onload, dll)
        $html = preg_replace('#\son[a-zA-Z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#is', '', $html);

        return trim($html);
    }

    /**
     * Decode MIME encoded-word header (contoh: =?UTF-8?Q?Maaf,=20Permintaan...?)
     */
    public function decodeMimeHeader(?string $text): string
    {
        if (empty($text)) return '';
        
        $clean = trim($text, '"\' ');
        
        if (str_contains($clean, '=?')) {
            if (function_exists('mb_decode_mimeheader')) {
                $clean = mb_decode_mimeheader($clean);
            } elseif (function_exists('iconv_mime_decode')) {
                $clean = iconv_mime_decode($clean, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            }
        }
        
        return $clean;
    }

    /**
     * Ekstrak teks bersih ringkas untuk pratinjau daftar email (buang style CSS, entitas &nbsp;, dll)
     */
    public function getCleanSnippet(?string $rawBody, int $limit = 85): string
    {
        if (empty($rawBody)) return '';

        // Hapus style, script, dan tag meta
        $text = preg_replace('#<style\b[^>]*>(.*?)<\/style>#is', ' ', $rawBody);
        $text = preg_replace('#<script\b[^>]*>(.*?)<\/script>#is', ' ', $text);
        $text = preg_replace('#<!--(.*?)-->#is', ' ', $text);

        // Hapus tag HTML
        $text = strip_tags($text);

        // Ubah entitas HTML seperti &nbsp;, &amp;, dll menjadi karakter biasa
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Bersihkan whitespace berulang
        $text = preg_replace('#\s+#u', ' ', $text);

        return \Illuminate\Support\Str::limit(trim($text), $limit);
    }

    public function render()
    {
        $user = $this->getAccount();
        $userId = $user ? $user->id : 0;

        // Ambil semua email sesuai filter
        $rawEmailList = MailboxEmail::where('virtual_user_id', $userId)
            ->where('folder', $this->activeFolder)
            ->when($this->searchQuery, function ($query) {
                $q = '%' . $this->searchQuery . '%';
                $query->where(function ($sub) use ($q) {
                    $sub->where('subject', 'like', $q)
                        ->orWhere('from_name', 'like', $q)
                        ->orWhere('from_email', 'like', $q)
                        ->orWhere('body', 'like', $q);
                });
            })
            ->when($this->activeFilter === 'starred', function ($query) {
                $query->where('is_starred', true);
            })
            ->when($this->activeFilter === 'unread', function ($query) {
                $query->where('is_read', false);
            })
            ->when($this->activeFilter === 'read', function ($query) {
                $query->where('is_read', true);
            })
            ->selectRaw("id, folder, from_name, from_email, `to`, subject, SUBSTR(body, 1, 300) as body_snippet, date_human, is_read, is_starred, attachments, spam_reason, spam_score, created_at")
            ->orderBy('id', 'desc')
            ->take(60)
            ->get();

        // Grouping Thread Percakapan: Hanya gabungkan jika SUBJEK SAMA DAN LAWAN BICARA (KONTAK) SAMA
        $groupedThreads = [];
        foreach ($rawEmailList as $item) {
            $normSubj = strtolower(trim(preg_replace('/^(Re:\s*|Fwd:\s*)+/i', '', $item->subject)));
            if (empty($normSubj) || $normSubj === '(tanpa subjek)') {
                $threadKey = 'single_' . $item->id;
            } else {
                // Tentukan lawan bicara: Jika di folder sent pakai 'to', selain itu pakai 'from_email'
                $contact = strtolower(trim($item->folder === 'sent' ? ($item->to ?: '') : ($item->from_email ?: '')));
                // Bersihkan alamat email jika ada format "Nama <email>"
                if (preg_match('/<([^>]+)>/', $contact, $cm)) {
                    $contact = strtolower(trim($cm[1]));
                }
                $threadKey = $contact . '::' . $normSubj;
            }

            if (!isset($groupedThreads[$threadKey])) {
                $groupedThreads[$threadKey] = [
                    'primary' => $item,
                    'count' => 1,
                    'has_unread' => !(bool)$item->is_read,
                    'has_starred' => (bool)$item->is_starred,
                ];
            } else {
                $groupedThreads[$threadKey]['count']++;
                if (!$item->is_read) {
                    $groupedThreads[$threadKey]['has_unread'] = true;
                }
                if ($item->is_starred) {
                    $groupedThreads[$threadKey]['has_starred'] = true;
                }
            }
        }

        $filteredEmails = collect($groupedThreads)->map(function ($thread) {
            $item = $thread['primary'];
            return [
                'id' => $item->id,
                'thread_count' => $thread['count'],
                'folder' => $item->folder,
                'from_name' => $this->decodeMimeHeader($item->from_name),
                'from_email' => $item->from_email,
                'to' => $item->to,
                'subject' => $this->decodeMimeHeader($item->subject),
                'snippet' => $this->getCleanSnippet($item->body_snippet ?? '', 85),
                'date' => $item->date_human ?: $item->created_at->format('d M, H:i'),
                'is_read' => !$thread['has_unread'],
                'is_starred' => $thread['has_starred'],
                'attachments' => $item->attachments ?: [],
                'spam_reason' => $item->spam_reason,
                'spam_score' => $item->spam_score,
            ];
        })->values();

        // Detail email dan Conversation Thread Tree (Gaya Gmail & Chat)
        $selectedEmail = null;
        $threadEmails = [];

        if ($this->viewMode === 'detail' && $this->selectedEmailId && $user) {
            $rawSelected = MailboxEmail::where('virtual_user_id', $user->id)->find($this->selectedEmailId);
            if ($rawSelected) {
                $selectedEmail = [
                    'id' => $rawSelected->id,
                    'folder' => $rawSelected->folder,
                    'from_name' => $this->decodeMimeHeader($rawSelected->from_name),
                    'from_email' => $rawSelected->from_email,
                    'to' => $rawSelected->to,
                    'subject' => $this->decodeMimeHeader($rawSelected->subject),
                    'date' => $rawSelected->date_human ?: $rawSelected->created_at->format('d M, H:i'),
                    'is_read' => (bool) $rawSelected->is_read,
                    'is_starred' => (bool) $rawSelected->is_starred,
                    'body' => $rawSelected->body,
                    'attachments' => $rawSelected->attachments ?: [],
                    'spam_reason' => $rawSelected->spam_reason,
                    'spam_score' => $rawSelected->spam_score,
                ];

                // Temukan seluruh thread percakapan HANYA yang melibatkan KONTAK YANG SAMA dan SUBJEK YANG SAMA
                $cleanSubj = trim(preg_replace('/^(Re:\s*|Fwd:\s*)+/i', '', $rawSelected->subject));
                if (!empty($cleanSubj) && strtolower($cleanSubj) !== '(tanpa subjek)') {
                    $otherParty = strtolower(trim($rawSelected->folder === 'sent' ? ($rawSelected->to ?: '') : ($rawSelected->from_email ?: '')));
                    if (preg_match('/<([^>]+)>/', $otherParty, $opm)) {
                        $otherParty = strtolower(trim($opm[1]));
                    }

                    $threadList = MailboxEmail::where('virtual_user_id', $user->id)
                        ->where(function ($q) use ($cleanSubj) {
                            $q->where('subject', 'like', "%{$cleanSubj}%");
                        })
                        ->where(function ($q) use ($otherParty) {
                            if (!empty($otherParty)) {
                                $q->where('from_email', 'like', "%{$otherParty}%")
                                  ->orWhere('to', 'like', "%{$otherParty}%");
                            }
                        })
                        ->orderBy('id', 'asc')
                        ->get();

                    foreach ($threadList as $tItem) {
                        $threadEmails[] = [
                            'id' => $tItem->id,
                            'is_current' => ($tItem->id === $rawSelected->id),
                            'folder' => $tItem->folder,
                            'from_name' => $this->decodeMimeHeader($tItem->from_name),
                            'from_email' => $tItem->from_email,
                            'to' => $tItem->to,
                            'subject' => $this->decodeMimeHeader($tItem->subject),
                            'date' => $tItem->date_human ?: $tItem->created_at->format('d M, H:i'),
                            'body' => $tItem->body,
                            'attachments' => $tItem->attachments ?: [],
                        ];
                    }
                }
            }
        }

        $rawCounts = MailboxEmail::where('virtual_user_id', $userId)
            ->selectRaw("folder, count(*) as total, sum(case when is_read = 0 then 1 else 0 end) as unread")
            ->groupBy('folder')
            ->get()
            ->keyBy('folder');

        $contactsCount = $userId ? MailboxContact::where('virtual_user_id', $userId)->count() : 0;
        $counts = [
            'inbox' => (int) ($rawCounts->get('inbox')?->unread ?? 0),
            'sent' => (int) ($rawCounts->get('sent')?->total ?? 0),
            'drafts' => (int) ($rawCounts->get('drafts')?->total ?? 0),
            'spam' => (int) ($rawCounts->get('spam')?->unread ?? 0),
            'trash' => (int) ($rawCounts->get('trash')?->total ?? 0),
            'contacts' => $contactsCount,
        ];

        // Daftar kontak untuk tampilan Buku Kontak dan auto-suggest di form Compose
        $contactsList = collect();
        $savedContactsList = collect();
        if ($userId) {
            $savedContactsList = MailboxContact::where('virtual_user_id', $userId)
                ->orderBy('name', 'asc')
                ->get(['id', 'name', 'email']);

            $contactsQuery = MailboxContact::where('virtual_user_id', $userId);
            if (!empty($this->contactSearchQuery)) {
                $term = '%' . trim($this->contactSearchQuery) . '%';
                $contactsQuery->where(function($q) use ($term) {
                    $q->where('name', 'like', $term)
                      ->orWhere('email', 'like', $term)
                      ->orWhere('company', 'like', $term)
                      ->orWhere('phone', 'like', $term);
                });
            }
            $contactsList = $contactsQuery->orderBy('last_communicated_at', 'desc')->orderBy('name', 'asc')->get();
        }

        return view('components.webmail.⚡mail-client', [
            'filteredEmails' => $filteredEmails,
            'selectedEmail' => $selectedEmail,
            'threadEmails' => $threadEmails,
            'counts' => $counts,
            'currentAccount' => $user,
            'contactsList' => $contactsList,
            'savedContactsList' => $savedContactsList,
        ])->layout('layouts.webmail', ['title' => 'Webmail Client - MailIDS']);
    }
};
?>

<div class="w-full h-full min-h-0 flex-1 flex flex-col bg-slate-950 overflow-hidden relative select-none"
     x-data="{ showFolderSidebar: true, showFolderList: true }">
    
    <!-- Top Modern Unified Header (Edge-to-Edge) -->
    <header class="h-14 sm:h-15 px-3 sm:px-5 bg-slate-900/95 backdrop-blur-md border-b border-slate-800/80 flex items-center justify-between gap-3 shrink-0 z-30">
        <!-- Left: Toggle Sidebar (Desktop & Mobile) + Brand Logo + Active Folder Badge -->
        <div class="flex items-center gap-2 sm:gap-3">
            <!-- Toggle Folder Sidebar Button (Bisa Hide & Unhide) -->
            <button @click="showFolderSidebar = !showFolderSidebar" 
                    class="p-2 rounded-xl text-slate-300 hover:text-white bg-slate-800/60 hover:bg-slate-800 border border-slate-700/60 transition-all flex items-center justify-center cursor-pointer" 
                    :title="showFolderSidebar ? 'Sembunyikan Panel Folder (Hide)' : 'Tampilkan Panel Folder (Unhide)'">
                <i data-lucide="menu" class="w-5 h-5"></i>
            </button>

            <!-- Brand Logo -->
            <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-xl bg-gradient-to-tr from-cyan-500 via-indigo-500 to-purple-600 flex items-center justify-center shadow-md shadow-indigo-500/20 ring-1 ring-white/20 shrink-0">
                    <i data-lucide="mail" class="w-4 h-4 text-white"></i>
                </div>
                <div class="flex items-center gap-1.5">
                    <span class="font-extrabold text-white text-base tracking-tight flex items-center gap-0.5">
                        Mail<span class="text-transparent bg-clip-text bg-gradient-to-r from-cyan-400 to-indigo-400">IDS</span>
                    </span>
                    <span class="hidden sm:inline-flex text-[9px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full bg-cyan-500/10 text-cyan-400 border border-cyan-500/20">
                        Webmail
                    </span>
                </div>
            </div>

            <!-- Active Folder Badge (Desktop / Tablet) -->
            <div class="hidden sm:flex items-center gap-1.5 px-3 py-1 rounded-xl bg-slate-950/80 border border-slate-800 text-xs text-slate-300 font-medium ml-1">
                <i data-lucide="{{ $activeFolder === 'inbox' ? 'inbox' : ($activeFolder === 'sent' ? 'send' : ($activeFolder === 'drafts' ? 'file-text' : ($activeFolder === 'spam' ? 'alert-octagon' : ($activeFolder === 'trash' ? 'trash-2' : ($activeFolder === 'contacts' ? 'users' : 'folder'))))) }}" class="w-3.5 h-3.5 text-cyan-400"></i>
                <span class="text-slate-400">Folder:</span>
                <span class="font-bold text-white capitalize">
                    {{ $activeFolder === 'inbox' ? 'Kotak Masuk' : ($activeFolder === 'sent' ? 'Terkirim' : ($activeFolder === 'drafts' ? 'Drafts' : ($activeFolder === 'spam' ? 'Spam' : ($activeFolder === 'trash' ? 'Sampah' : ($activeFolder === 'contacts' ? 'Kontak' : $activeFolder))))) }}
                </span>
            </div>
        </div>

        <!-- Center Notification (Flash message) -->
        @if (session()->has('webmail_msg'))
            <div class="hidden md:flex items-center gap-2 px-3.5 py-1 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs shadow-sm animate-pulse">
                <i data-lucide="check-circle-2" class="w-3.5 h-3.5 text-emerald-400"></i>
                <span class="truncate max-w-xs">{{ session('webmail_msg') }}</span>
            </div>
        @endif

        <!-- Right: Action Tools & User Profile -->
        <div class="flex items-center gap-2 sm:gap-2.5">
            <!-- Segarkan Inbox Button -->
            <button type="button" 
                    wire:click="refreshInbox" 
                    class="p-2 sm:px-3 sm:py-1.5 rounded-xl bg-slate-800/80 hover:bg-slate-700/80 text-slate-300 hover:text-white border border-slate-700/70 text-xs font-semibold flex items-center gap-1.5 transition-all shadow-sm"
                    title="Segarkan Kotak Masuk (Tarik Email Baru)">
                <i data-lucide="rotate-cw" class="w-4 h-4 sm:w-3.5 sm:h-3.5 text-cyan-400" wire:loading.class="animate-spin" wire:target="refreshInbox"></i>
                <span class="hidden sm:inline">Segarkan</span>
            </button>

            <!-- Pengaturan Akun Button -->
            <button type="button" 
                    wire:click="openSettingsModal" 
                    class="p-2 sm:px-3 sm:py-1.5 rounded-xl bg-slate-800/80 hover:bg-indigo-600/30 text-slate-300 hover:text-indigo-300 border border-slate-700/70 hover:border-indigo-500/40 text-xs font-semibold flex items-center gap-1.5 transition-all shadow-sm"
                    title="Pengaturan Akun Pengguna">
                <i data-lucide="settings" class="w-4 h-4 sm:w-3.5 sm:h-3.5 text-indigo-400"></i>
                <span class="hidden sm:inline">Pengaturan</span>
            </button>

            <!-- Dovecot IMAP Active Badge (Large screens) -->
            <div class="hidden xl:flex items-center gap-2 px-3 py-1.5 rounded-full bg-slate-950/80 border border-slate-800 text-[11px] text-slate-400 shadow-inner">
                <span class="relative flex h-2 w-2">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                </span>
                <span class="font-medium text-slate-300">Dovecot IMAP</span>
            </div>

            <div class="h-5 w-px bg-slate-800 hidden sm:block"></div>

            <!-- User Mailbox Account Pill -->
            <div class="px-2.5 py-1 sm:px-3 sm:py-1.5 rounded-xl bg-slate-950/80 border border-slate-800 flex items-center gap-2 shadow-inner">
                <div class="w-6 h-6 rounded-lg bg-gradient-to-tr from-cyan-500/20 to-indigo-500/20 border border-cyan-500/30 text-cyan-300 flex items-center justify-center font-bold text-xs shrink-0">
                    {{ strtoupper(substr($currentAccount->email ?? (auth('mailbox')->user()->email ?? 'U'), 0, 1)) }}
                </div>
                <div class="hidden lg:flex flex-col text-left max-w-[180px]">
                    <span class="text-[11px] font-semibold text-slate-200 font-mono leading-none truncate">
                        {{ $currentAccount->email ?? (auth('mailbox')->user()->email ?? 'user@domain.com') }}
                    </span>
                    <span class="text-[9px] text-emerald-400 font-medium flex items-center gap-1 leading-tight mt-0.5">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span> IMAP/SMTP
                    </span>
                </div>
            </div>

            <!-- Logout Button -->
            <form method="POST" action="{{ route('webmail.logout') }}" class="inline">
                @csrf
                <button type="submit" 
                        class="p-2 sm:px-3 sm:py-1.5 rounded-xl bg-slate-800/80 hover:bg-rose-500/15 text-slate-300 hover:text-rose-400 text-xs font-semibold flex items-center gap-1.5 transition-all border border-slate-700/80 hover:border-rose-500/30 shadow-sm" 
                        title="Keluar dari Webmail">
                    <i data-lucide="log-out" class="w-4 h-4 sm:w-3.5 sm:h-3.5"></i>
                    <span class="hidden md:inline">Keluar</span>
                </button>
            </form>
        </div>
    </header>

    <!-- Edge-to-Edge Webmail Work Area -->
    <div class="flex-1 min-h-0 flex overflow-hidden relative w-full h-full">
        <!-- Mobile Backdrop for Folder Drawer -->
        <div x-show="showFolderSidebar" 
             @click="showFolderSidebar = false" 
             x-transition:enter="transition-opacity ease-linear duration-200"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="transition-opacity ease-linear duration-200"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             class="fixed inset-0 bg-black/60 z-30 md:hidden backdrop-blur-xs" 
             style="display: none;"></div>

        <!-- Kolom 1: Folder Navigasi (Bisa Hide dan Unhide di Desktop & Mobile) -->
        <div :class="showFolderSidebar ? 'translate-x-0 w-64 md:w-60 lg:w-64 p-3 border-r border-slate-800/80 opacity-100' : '-translate-x-full md:translate-x-0 md:w-0 md:p-0 md:border-none md:opacity-0'"
             class="fixed md:static inset-y-0 left-0 bg-slate-950 md:bg-slate-950/90 flex flex-col justify-between shrink-0 z-40 md:z-20 transition-all duration-300 ease-in-out h-full overflow-hidden shadow-2xl md:shadow-none">
            
            <!-- Tulis Pesan Button at top of Sidebar (Modern Standard like Gmail/Outlook) -->
            <div class="shrink-0 mb-3">
                <div class="flex items-center justify-between md:hidden pb-2 mb-2 border-b border-slate-800">
                    <span class="text-xs font-bold text-slate-300">Folder</span>
                    <button @click="showFolderSidebar = false" class="text-slate-400 hover:text-white p-1 rounded-lg hover:bg-slate-800">
                        <i data-lucide="x" class="w-4 h-4"></i>
                    </button>
                </div>

                <button wire:click="$set('showComposeModal', true)" @click="if (window.innerWidth < 768) showFolderSidebar = false"
                        class="w-full px-4 py-2.5 bg-gradient-to-r from-cyan-500 via-indigo-600 to-purple-600 hover:from-cyan-400 hover:to-indigo-500 text-white text-xs font-bold rounded-xl flex items-center justify-center gap-2 transition-all shadow-lg shadow-indigo-600/20 hover:shadow-cyan-500/30 hover:scale-[1.01] active:scale-[0.99] cursor-pointer">
                    <i data-lucide="edit-3" class="w-4 h-4"></i>
                    <span>Tulis Pesan</span>
                </button>
            </div>

            <!-- Scrollable Folder List Area -->
            <div class="flex-1 min-h-0 overflow-y-auto space-y-1.5 pr-1 pb-2">

                <!-- Kotak Masuk (Inbox) -->
                <button wire:click="selectFolder('inbox')" @click="if (window.innerWidth < 768) showFolderSidebar = false"
                        class="w-full flex items-center justify-between px-3 py-2 rounded-xl text-xs font-medium transition-all group cursor-pointer {{ $activeFolder === 'inbox' ? 'bg-gradient-to-r from-cyan-600 to-indigo-600 text-white font-bold shadow-lg shadow-indigo-600/20' : 'text-slate-400 hover:bg-slate-800/70 hover:text-slate-200' }}">
                    <div class="flex items-center gap-2.5">
                        <div class="p-1 rounded-lg {{ $activeFolder === 'inbox' ? 'bg-white/20' : 'bg-slate-800/50 group-hover:bg-slate-800 text-cyan-400' }}">
                            <i data-lucide="inbox" class="w-3.5 h-3.5"></i>
                        </div>
                        <span>Kotak Masuk</span>
                    </div>
                    @if($counts['inbox'] > 0)
                        <span class="text-[10px] px-2 py-0.5 rounded-full {{ $activeFolder === 'inbox' ? 'bg-white/20 text-white' : 'bg-cyan-500/15 text-cyan-300 border border-cyan-500/30' }} font-bold">
                            {{ $counts['inbox'] }}
                        </span>
                    @endif
                </button>

                <!-- Sent -->
                <button wire:click="selectFolder('sent')" @click="if (window.innerWidth < 768) showFolderSidebar = false"
                        class="w-full flex items-center justify-between px-3 py-2 rounded-xl text-xs font-medium transition-all group cursor-pointer {{ $activeFolder === 'sent' ? 'bg-gradient-to-r from-cyan-600 to-indigo-600 text-white font-bold shadow-lg shadow-indigo-600/20' : 'text-slate-400 hover:bg-slate-800/70 hover:text-slate-200' }}">
                    <div class="flex items-center gap-2.5">
                        <div class="p-1 rounded-lg {{ $activeFolder === 'sent' ? 'bg-white/20' : 'bg-slate-800/50 group-hover:bg-slate-800 text-indigo-400' }}">
                            <i data-lucide="send" class="w-3.5 h-3.5"></i>
                        </div>
                        <span>Terkirim</span>
                    </div>
                </button>

                <!-- Drafts -->
                <button wire:click="selectFolder('drafts')" @click="if (window.innerWidth < 768) showFolderSidebar = false"
                        class="w-full flex items-center justify-between px-3 py-2 rounded-xl text-xs font-medium transition-all group cursor-pointer {{ $activeFolder === 'drafts' ? 'bg-gradient-to-r from-cyan-600 to-indigo-600 text-white font-bold shadow-lg shadow-indigo-600/20' : 'text-slate-400 hover:bg-slate-800/70 hover:text-slate-200' }}">
                    <div class="flex items-center gap-2.5">
                        <div class="p-1 rounded-lg {{ $activeFolder === 'drafts' ? 'bg-white/20' : 'bg-slate-800/50 group-hover:bg-slate-800 text-rose-400' }}">
                            <i data-lucide="file-text" class="w-3.5 h-3.5"></i>
                        </div>
                        <span>Drafts</span>
                    </div>
                    @if($counts['drafts'] > 0)
                        <span class="text-[10px] px-2 py-0.5 rounded-full {{ $activeFolder === 'drafts' ? 'bg-white/20 text-white' : 'bg-rose-500/15 text-rose-300 border border-rose-500/30' }} font-bold">
                            {{ $counts['drafts'] }}
                        </span>
                    @endif
                </button>

                <!-- Folder Spam -->
                <button wire:click="selectFolder('spam')" @click="if (window.innerWidth < 768) showFolderSidebar = false"
                        class="w-full flex items-center justify-between px-3 py-2 rounded-xl text-xs font-medium transition-all group cursor-pointer {{ $activeFolder === 'spam' ? 'bg-gradient-to-r from-cyan-600 to-indigo-600 text-white font-bold shadow-lg shadow-indigo-600/20' : 'text-slate-400 hover:bg-slate-800/70 hover:text-slate-200' }}">
                    <div class="flex items-center gap-2.5">
                        <div class="p-1 rounded-lg {{ $activeFolder === 'spam' ? 'bg-white/20' : 'bg-slate-800/50 group-hover:bg-slate-800 text-amber-400' }}">
                            <i data-lucide="alert-octagon" class="w-3.5 h-3.5"></i>
                        </div>
                        <span>Spam</span>
                    </div>
                    @if($counts['spam'] > 0)
                        <span class="text-[10px] px-2 py-0.5 rounded-full {{ $activeFolder === 'spam' ? 'bg-white/20 text-white' : 'bg-amber-500/20 text-amber-300 border border-amber-500/30' }} font-bold">
                            {{ $counts['spam'] }}
                        </span>
                    @endif
                </button>

                <!-- Trash -->
                <button wire:click="selectFolder('trash')" @click="if (window.innerWidth < 768) showFolderSidebar = false"
                        class="w-full flex items-center justify-between px-3 py-2 rounded-xl text-xs font-medium transition-all group cursor-pointer {{ $activeFolder === 'trash' ? 'bg-gradient-to-r from-cyan-600 to-indigo-600 text-white font-bold shadow-lg shadow-indigo-600/20' : 'text-slate-400 hover:bg-slate-800/70 hover:text-slate-200' }}">
                    <div class="flex items-center gap-2.5">
                        <div class="p-1 rounded-lg {{ $activeFolder === 'trash' ? 'bg-white/20' : 'bg-slate-800/50 group-hover:bg-slate-800 text-slate-400' }}">
                            <i data-lucide="trash" class="w-3.5 h-3.5"></i>
                        </div>
                        <span>Sampah</span>
                    </div>
                </button>

                <!-- Kontak (Otomatis Tersimpan dari Komunikasi) -->
                <button wire:click="selectFolder('contacts')" @click="if (window.innerWidth < 768) showFolderSidebar = false"
                        class="w-full flex items-center justify-between px-3 py-2 rounded-xl text-xs font-medium transition-all group cursor-pointer {{ $activeFolder === 'contacts' ? 'bg-gradient-to-r from-emerald-600 to-teal-600 text-white font-bold shadow-lg shadow-emerald-600/20' : 'text-slate-400 hover:bg-slate-800/70 hover:text-slate-200' }}">
                    <div class="flex items-center gap-2.5">
                        <div class="p-1 rounded-lg {{ $activeFolder === 'contacts' ? 'bg-white/20' : 'bg-slate-800/50 group-hover:bg-slate-800 text-emerald-400' }}">
                            <i data-lucide="users" class="w-3.5 h-3.5"></i>
                        </div>
                        <span>Kontak</span>
                    </div>
                    @if(($counts['contacts'] ?? 0) > 0)
                        <span class="text-[10px] px-2 py-0.5 rounded-full {{ $activeFolder === 'contacts' ? 'bg-white/20 text-white' : 'bg-emerald-500/15 text-emerald-300 border border-emerald-500/30' }} font-bold">
                            {{ $counts['contacts'] }}
                        </span>
                    @endif
                </button>

                <!-- Bagian Folder (Bisa Hide & Unhide, Label murni 'Folder', Tanpa Folder Default) -->
                <div class="pt-3 mt-2 border-t border-slate-800/80" x-data="{ showFolderGroup: true }">
                    <div class="flex items-center justify-between px-2 mb-1.5">
                        <!-- Label murni 'Folder' dengan toggle chevron hide/unhide -->
                        <button type="button" 
                                @click="showFolderGroup = !showFolderGroup" 
                                class="flex items-center gap-1.5 text-[11px] font-bold text-slate-400 hover:text-slate-200 uppercase tracking-wider transition-colors select-none group cursor-pointer"
                                title="Sembunyikan / Tampilkan Daftar Folder">
                            <i data-lucide="chevron-down" 
                               class="w-3.5 h-3.5 transition-transform duration-200 text-slate-500 group-hover:text-cyan-400" 
                               :class="showFolderGroup ? '' : '-rotate-90'"></i>
                            <span>Folder</span>
                        </button>
                        
                        <!-- Tombol Tambah Folder Baru -->
                        <button type="button" 
                                @click="$wire.set('showCreateFolderModal', true)" 
                                class="p-1 rounded-lg hover:bg-indigo-500/20 text-indigo-400 hover:text-indigo-300 transition-colors cursor-pointer" 
                                title="Buat Folder Baru">
                            <i data-lucide="folder-plus" class="w-3.5 h-3.5"></i>
                        </button>
                    </div>

                    <!-- Daftar Folder Pengguna (Bisa Hide & Unhide) -->
                    <div x-show="showFolderGroup" x-collapse class="space-y-1">
                        @forelse($customFolders as $folder)
                        <div class="group relative flex items-center justify-between rounded-xl transition-all {{ $activeFolder === $folder ? 'bg-gradient-to-r from-cyan-600 to-indigo-600 text-white font-bold shadow-md shadow-indigo-600/20' : 'text-slate-400 hover:bg-slate-800/70 hover:text-white' }}">
                            <button wire:click="selectFolder('{{ $folder }}')" @click="if (window.innerWidth < 768) showFolderSidebar = false"
                                    class="w-full flex items-center gap-2 px-2.5 py-1.5 text-xs font-medium truncate text-left cursor-pointer">
                                <i data-lucide="folder" class="w-3.5 h-3.5 {{ $activeFolder === $folder ? 'text-white' : 'text-cyan-400' }} shrink-0"></i>
                                <span class="truncate">{{ $folder }}</span>
                            </button>
                            
                            <!-- Tombol Hapus Folder -->
                            <button type="button" 
                                    wire:click="deleteFolder('{{ $folder }}')" 
                                    wire:confirm="Yakin ingin menghapus folder '{{ $folder }}'? Email di dalamnya akan dipindahkan ke Kotak Masuk."
                                    class="opacity-0 group-hover:opacity-100 p-1 mr-1 rounded-lg hover:bg-rose-500/20 text-slate-400 hover:text-rose-400 transition-all shrink-0 cursor-pointer" 
                                    title="Hapus folder {{ $folder }}">
                                <i data-lucide="trash-2" class="w-3 h-3"></i>
                            </button>
                        </div>
                        @empty
                        <div class="px-2.5 py-2 text-[11px] text-slate-500 italic">
                            Belum ada folder. Klik <button type="button" class="text-cyan-400 font-semibold hover:underline inline" @click="$wire.set('showCreateFolderModal', true)">+</button> untuk menambah folder.
                        </div>
                        @endforelse
                    </div>
                </div>
            </div>

            <!-- Kuota Info Dinamis dari Database virtual_users & Dovecot (Fixed Bottom Widget) -->
            @php
                $usedFormatted = $currentAccount ? $currentAccount->formatted_used : '1.16 GB';
                $quotaFormatted = $currentAccount ? $currentAccount->formatted_quota : '5 GB';
                $usagePercent = $currentAccount ? $currentAccount->quota_usage_percent : 23;
                $barColor = $usagePercent >= 90 ? 'from-rose-500 to-red-600' : ($usagePercent >= 75 ? 'from-amber-400 to-orange-500' : 'from-cyan-400 to-indigo-500');
            @endphp
            <div class="mt-2 pt-2 border-t border-slate-800/80 shrink-0">
                <div class="p-2.5 rounded-xl bg-slate-900/90 border border-slate-800 text-[11px] text-slate-400 space-y-1.5 shadow-inner" title="Kapasitas penyimpanan akun {{ $currentAccount->email ?? '' }}">
                    <div class="flex items-center justify-between font-medium">
                        <span class="flex items-center gap-1.5">
                            <i data-lucide="hard-drive" class="w-3.5 h-3.5 text-cyan-400"></i>
                            <span class="text-slate-300 font-semibold text-[11px]">Penyimpanan</span>
                        </span>
                        <span class="text-slate-400 font-mono text-[10px]">{{ $usedFormatted }} / {{ $quotaFormatted }}</span>
                    </div>
                    <div class="w-full h-1.5 bg-slate-950 rounded-full overflow-hidden p-0.5 border border-slate-800">
                        <div class="h-full bg-gradient-to-r {{ $barColor }} rounded-full transition-all duration-500" style="width: {{ $usagePercent }}%"></div>
                    </div>
                    <div class="flex justify-between text-[9px] text-slate-500 pt-0.5">
                        <span>Terpakai {{ $usagePercent }}%</span>
                        @if($usagePercent >= 90)
                            <span class="text-rose-400 font-bold flex items-center gap-0.5">
                                <i data-lucide="alert-triangle" class="w-2.5 h-2.5"></i> Hampir Penuh
                            </span>
                        @else
                            <span class="text-emerald-400 font-medium">Tersedia {{ 100 - $usagePercent }}%</span>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <!-- Kolom Konten Utama: Kontak vs Mode Daftar (Gmail/Hostinger List) vs Mode Detail Pesan -->
        <div class="flex-1 flex flex-col min-w-0 h-full overflow-hidden bg-slate-950">

            @if($activeFolder === 'contacts')
            <!-- ========================================== -->
            <!-- 0. MODE BUKU KONTAK (CONTACTS ADDRESS BOOK) -->
            <!-- ========================================== -->
            <div class="flex-1 flex flex-col min-w-0 h-full overflow-hidden" wire:key="contacts-view">
                
                <!-- Sub-Header: Judul Kontak, Pencarian & Tombol Aksi -->
                <div class="p-3.5 sm:px-6 border-b border-slate-800/80 bg-slate-900/60 backdrop-blur-md flex flex-wrap items-center justify-between gap-3 shrink-0">
                    <div class="flex items-center gap-3">
                        <div class="flex items-center gap-2.5">
                            <div class="w-8 h-8 rounded-xl bg-gradient-to-tr from-emerald-500/20 to-teal-500/20 border border-emerald-500/30 flex items-center justify-center text-emerald-400 shadow-sm">
                                <i data-lucide="users" class="w-4 h-4"></i>
                            </div>
                            <div>
                                <h1 class="text-base sm:text-lg font-bold text-white flex items-center gap-2">
                                    <span>Buku Kontak</span>
                                    <span class="text-xs font-mono font-semibold px-2 py-0.5 rounded-full bg-emerald-500/15 text-emerald-300 border border-emerald-500/30">
                                        {{ count($contactsList) }} Kontak
                                    </span>
                                </h1>
                            </div>
                        </div>
                    </div>

                    <!-- Search Input Kontak & Tombol Aksi -->
                    <div class="flex items-center gap-2.5 flex-1 max-w-md ml-auto">
                        <div class="relative flex-1">
                            <i data-lucide="search" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
                            <input type="text" 
                                   wire:model.live.debounce.250ms="contactSearchQuery" 
                                   placeholder="Cari kontak (nama, email, perusahaan)..."
                                   class="w-full pl-9 pr-3.5 py-1.5 rounded-xl bg-slate-950/80 border border-slate-800 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 transition-all shadow-inner">
                            @if(!empty($contactSearchQuery))
                                <button type="button" 
                                        wire:click="$set('contactSearchQuery', '')" 
                                        class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-500 hover:text-slate-300 cursor-pointer">
                                    <i data-lucide="x" class="w-3.5 h-3.5"></i>
                                </button>
                            @endif
                        </div>

                        <!-- Tombol Sinkronkan Riwayat Komunikasi -->
                        <button type="button" 
                                wire:click="syncContactsFromHistory" 
                                class="p-2 sm:px-3 sm:py-1.5 rounded-xl bg-slate-800/80 hover:bg-slate-700/80 text-slate-300 hover:text-white border border-slate-700/70 text-xs font-semibold flex items-center gap-1.5 transition-all shadow-sm cursor-pointer"
                                title="Sinkronkan kontak dari seluruh riwayat email masuk dan keluar">
                            <i data-lucide="refresh-cw" class="w-3.5 h-3.5 text-teal-400" wire:loading.class="animate-spin" wire:target="syncContactsFromHistory"></i>
                            <span class="hidden md:inline">Sinkronkan</span>
                        </button>

                        <!-- Tombol Tambah Kontak Baru -->
                        <button type="button" 
                                wire:click="openCreateContactModal" 
                                class="px-3.5 py-1.5 bg-gradient-to-r from-emerald-500 to-teal-600 hover:from-emerald-400 hover:to-teal-500 text-white text-xs font-bold rounded-xl flex items-center gap-1.5 transition-all shadow-md shadow-emerald-600/20 hover:scale-[1.02] active:scale-[0.98] cursor-pointer shrink-0">
                            <i data-lucide="user-plus" class="w-3.5 h-3.5"></i>
                            <span>Tambah Kontak</span>
                        </button>
                    </div>
                </div>

                <!-- Notifikasi Flash Kontak Sukses -->
                @if (session()->has('contact_success'))
                <div class="mx-4 sm:mx-6 mt-3 p-3 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 text-xs flex items-center justify-between gap-2 shadow-sm">
                    <div class="flex items-center gap-2">
                        <i data-lucide="check-circle" class="w-4 h-4 text-emerald-400 shrink-0"></i>
                        <span>{{ session('contact_success') }}</span>
                    </div>
                    <button type="button" @click="$el.parentElement.remove()" class="text-emerald-400/80 hover:text-emerald-200 cursor-pointer">
                        <i data-lucide="x" class="w-3.5 h-3.5"></i>
                    </button>
                </div>
                @endif

                <!-- Area Grid Kontak -->
                <div class="flex-1 overflow-y-auto p-4 sm:p-6 custom-scrollbar">
                    @if($contactsList->isNotEmpty())
                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 gap-4">
                        @foreach($contactsList as $c)
                        @php
                            $initial = strtoupper(substr($c->name ?: ($c->email ?: 'C'), 0, 1));
                            $gradientIndex = (crc32($c->email) % 5);
                            $gradients = [
                                'from-cyan-600 to-blue-600',
                                'from-indigo-600 to-purple-600',
                                'from-emerald-600 to-teal-600',
                                'from-amber-600 to-orange-600',
                                'from-rose-600 to-pink-600',
                            ];
                            $cardGradient = $gradients[abs($gradientIndex)];
                        @endphp
                        <div class="group relative rounded-2xl bg-slate-900/80 hover:bg-slate-900 border border-slate-800/80 hover:border-slate-700/90 p-4 transition-all duration-200 shadow-md hover:shadow-xl hover:shadow-cyan-500/5 flex flex-col justify-between">
                            <div>
                                <!-- Header Kartu Kontak: Avatar, Nama, Email & Tombol Aksi -->
                                <div class="flex items-start justify-between gap-3">
                                    <div class="flex items-center gap-3 min-w-0">
                                        <div class="w-10 h-10 rounded-xl bg-gradient-to-tr {{ $cardGradient }} text-white font-bold flex items-center justify-center text-sm shadow-md shrink-0">
                                            {{ $initial }}
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <h4 class="text-sm font-bold text-white truncate group-hover:text-cyan-300 transition-colors" title="{{ $c->name ?: $c->email }}">
                                                {{ $c->name ?: explode('@', $c->email)[0] }}
                                            </h4>
                                            <span class="text-xs text-cyan-400 font-mono truncate block" title="{{ $c->email }}">
                                                {{ $c->email }}
                                            </span>
                                        </div>
                                    </div>

                                    <!-- Action Menu: Edit & Hapus -->
                                    <div class="flex items-center gap-1 opacity-80 group-hover:opacity-100 transition-opacity">
                                        <button type="button" 
                                                wire:click="openEditContactModal({{ $c->id }})" 
                                                class="p-1.5 rounded-lg text-slate-400 hover:text-cyan-300 hover:bg-slate-800 transition-colors cursor-pointer" 
                                                title="Edit kontak">
                                            <i data-lucide="pencil" class="w-3.5 h-3.5"></i>
                                        </button>
                                        <button type="button" 
                                                wire:click="deleteContact({{ $c->id }})" 
                                                wire:confirm="Yakin ingin menghapus kontak '{{ $c->name ?: $c->email }}'?" 
                                                class="p-1.5 rounded-lg text-slate-400 hover:text-rose-400 hover:bg-rose-500/10 transition-colors cursor-pointer" 
                                                title="Hapus kontak">
                                            <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                        </button>
                                    </div>
                                </div>

                                <!-- Detail Kontak: Perusahaan, Telepon, Catatan -->
                                <div class="mt-3 space-y-1.5 text-xs text-slate-400">
                                    @if($c->company)
                                    <div class="flex items-center gap-2 truncate">
                                        <i data-lucide="building" class="w-3.5 h-3.5 text-slate-500 shrink-0"></i>
                                        <span class="truncate">{{ $c->company }}</span>
                                    </div>
                                    @endif

                                    @if($c->phone)
                                    <div class="flex items-center gap-2 truncate">
                                        <i data-lucide="phone" class="w-3.5 h-3.5 text-slate-500 shrink-0"></i>
                                        <span class="truncate font-mono">{{ $c->phone }}</span>
                                    </div>
                                    @endif

                                    @if($c->notes)
                                    <div class="text-[11px] text-slate-400/90 italic bg-slate-950/60 p-2 rounded-xl border border-slate-800/80 line-clamp-2 mt-2">
                                        "{{ $c->notes }}"
                                    </div>
                                    @endif
                                </div>
                            </div>

                            <!-- Footer Kartu: Riwayat Komunikasi & Tombol Kirim Email -->
                            <div class="mt-4 pt-3 border-t border-slate-800/80 flex flex-col gap-2.5">
                                <div class="flex items-center justify-between text-[11px] text-slate-500">
                                    <span class="flex items-center gap-1 font-mono">
                                        <i data-lucide="message-square" class="w-3 h-3 text-emerald-400"></i>
                                        <span>{{ $c->communication_count }}x komunikasi</span>
                                    </span>
                                    <span>
                                        {{ $c->last_communicated_at ? $c->last_communicated_at->diffForHumans() : 'Baru saja' }}
                                    </span>
                                </div>

                                <button type="button" 
                                        wire:click="composeToContact('{{ $c->email }}')" 
                                        class="w-full py-1.5 px-3 rounded-xl bg-gradient-to-r from-cyan-500/10 via-indigo-500/10 to-purple-500/10 hover:from-cyan-500 hover:to-indigo-600 border border-cyan-500/30 hover:border-transparent text-cyan-300 hover:text-white text-xs font-semibold flex items-center justify-center gap-1.5 transition-all shadow-sm cursor-pointer group-hover:border-cyan-500/60">
                                    <i data-lucide="send" class="w-3.5 h-3.5"></i>
                                    <span>Kirim Pesan</span>
                                </button>
                            </div>
                        </div>
                        @endforeach
                    </div>
                    @else
                    <!-- Tampilan Kosong (Empty State) -->
                    <div class="flex flex-col items-center justify-center h-full min-h-[350px] text-center p-6">
                        <div class="w-16 h-16 rounded-3xl bg-slate-900 border border-slate-800 flex items-center justify-center text-slate-500 mb-4 shadow-inner">
                            <i data-lucide="users" class="w-8 h-8 text-cyan-400/60"></i>
                        </div>
                        @if(!empty($contactSearchQuery))
                            <h3 class="text-base font-bold text-white mb-1">Kontak tidak ditemukan</h3>
                            <p class="text-xs text-slate-400 max-w-sm mb-4">
                                Tidak ada kontak yang cocok dengan kata kunci "<span class="text-cyan-400 font-semibold">{{ $contactSearchQuery }}</span>".
                            </p>
                            <button type="button" wire:click="$set('contactSearchQuery', '')" class="text-xs text-cyan-400 hover:underline cursor-pointer">
                                Bersihkan Pencarian
                            </button>
                        @else
                            <h3 class="text-base font-bold text-white mb-1">Belum Ada Kontak Tersimpan</h3>
                            <p class="text-xs text-slate-400 max-w-md mb-5 leading-relaxed">
                                Semua orang yang pernah bertukar email dengan Anda (baik yang Anda kirimi maupun yang mengirim email ke Anda) akan otomatis tersimpan di sini. Anda juga dapat menambahkan kontak secara manual.
                            </p>
                            <div class="flex items-center gap-3">
                                <button type="button" 
                                        wire:click="openCreateContactModal" 
                                        class="px-4 py-2 bg-gradient-to-r from-emerald-500 to-teal-600 hover:from-emerald-400 hover:to-teal-500 text-white text-xs font-bold rounded-xl flex items-center gap-2 transition-all shadow-md shadow-emerald-600/20 cursor-pointer">
                                    <i data-lucide="user-plus" class="w-4 h-4"></i>
                                    <span>Tambah Kontak Baru</span>
                                </button>
                                <button type="button" 
                                        wire:click="syncContactsFromHistory" 
                                        class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white text-xs font-medium rounded-xl flex items-center gap-2 transition-all border border-slate-700 cursor-pointer">
                                    <i data-lucide="refresh-cw" class="w-4 h-4 text-cyan-400"></i>
                                    <span>Pindai Riwayat Email</span>
                                </button>
                            </div>
                        @endif
                    </div>
                    @endif
                </div>
            </div>
            @elseif($viewMode === 'list')
            <!-- ========================================== -->
            <!-- 1. MODE DAFTAR EMAIL (GMAIL / HOSTINGER)    -->
            <!-- ========================================== -->
            <div class="flex-1 flex flex-col min-w-0 h-full overflow-hidden" wire:key="email-list-view">
                
                <!-- Sub-Header: Judul Folder, Filter Chips (All mail, Unread, Read, Starred) & Search -->
                <div class="p-3.5 sm:px-6 border-b border-slate-800/80 bg-slate-900/60 backdrop-blur-md flex flex-wrap items-center justify-between gap-3 shrink-0">
                    <div class="flex items-center gap-3">
                        <div class="flex items-center gap-2">
                            <h1 class="text-base sm:text-lg font-bold text-white capitalize flex items-center gap-2">
                                <span>{{ $activeFolder === 'inbox' ? 'Kotak Masuk' : ($activeFolder === 'sent' ? 'Terkirim' : ($activeFolder === 'drafts' ? 'Drafts' : ($activeFolder === 'spam' ? 'Spam' : ($activeFolder === 'trash' ? 'Sampah' : $activeFolder)))) }}</span>
                                @if(count($filteredEmails) > 0)
                                    <span class="text-xs font-mono font-normal text-slate-400">({{ count($filteredEmails) }})</span>
                                @endif
                            </h1>
                        </div>

                        <!-- Filter Chips: All mail, Unread, Read, Starred (Gaya Hostinger/Gmail) -->
                        <div class="flex items-center gap-1.5 overflow-x-auto py-0.5">
                            <button wire:click="setFilter('all')"
                                    class="px-3 py-1 rounded-full text-xs font-semibold transition-all {{ $activeFilter === 'all' ? 'bg-cyan-500 text-slate-950 shadow-md shadow-cyan-500/20' : 'bg-slate-800/70 text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                                Semua
                            </button>
                            <button wire:click="setFilter('unread')"
                                    class="px-3 py-1 rounded-full text-xs font-semibold transition-all {{ $activeFilter === 'unread' ? 'bg-cyan-500 text-slate-950 shadow-md shadow-cyan-500/20' : 'bg-slate-800/70 text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                                Belum Dibaca
                            </button>
                            <button wire:click="setFilter('read')"
                                    class="px-3 py-1 rounded-full text-xs font-semibold transition-all {{ $activeFilter === 'read' ? 'bg-cyan-500 text-slate-950 shadow-md shadow-cyan-500/20' : 'bg-slate-800/70 text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                                Sudah Dibaca
                            </button>
                            <button wire:click="setFilter('starred')"
                                    class="px-3 py-1 rounded-full text-xs font-semibold transition-all flex items-center gap-1 {{ $activeFilter === 'starred' ? 'bg-amber-400 text-slate-950 shadow-md shadow-amber-400/20' : 'bg-slate-800/70 text-slate-300 hover:bg-slate-800 hover:text-white' }}">
                                <i data-lucide="star" class="w-3 h-3 {{ $activeFilter === 'starred' ? 'fill-slate-950' : 'text-amber-400' }}"></i>
                                Berbintang
                            </button>
                        </div>
                    </div>

                    <!-- Search Input memanjang & Quick Batch Actions -->
                    <div class="flex items-center gap-3 w-full sm:w-auto">
                        <div class="relative flex-1 sm:w-64">
                            <i data-lucide="search" class="w-4 h-4 absolute left-3 top-2.5 text-slate-400"></i>
                            <input type="text" wire:model.live.debounce.200ms="searchQuery" placeholder="Cari pesan atau pengirim..." 
                                   class="w-full pl-9 pr-3.5 py-1.5 bg-slate-950/80 border border-slate-700/80 rounded-full text-xs text-white placeholder-slate-500 focus:outline-none focus:border-cyan-500 transition-all shadow-inner">
                        </div>

                        @if($activeFolder === 'trash' && count($filteredEmails) > 0)
                            <button wire:click="emptyTrash" wire:confirm="Kosongkan seluruh folder Sampah?" class="text-xs text-rose-400 hover:underline flex items-center gap-1 font-semibold shrink-0">
                                <i data-lucide="trash" class="w-3.5 h-3.5"></i> Kosongkan Sampah
                            </button>
                        @elseif($activeFolder === 'spam' && count($filteredEmails) > 0)
                            <button wire:click="emptySpam" wire:confirm="Hapus semua spam?" class="text-xs text-rose-400 hover:underline flex items-center gap-1 font-semibold shrink-0">
                                <i data-lucide="trash-2" class="w-3.5 h-3.5"></i> Hapus Spam
                            </button>
                        @endif
                    </div>
                </div>

                <!-- Action Bar: Select All Checkbox & Bulk Operations -->
                <div class="px-4 sm:px-6 py-2 border-b border-slate-800/80 bg-slate-950/40 flex items-center justify-between text-xs text-slate-400 shrink-0">
                    <div class="flex items-center gap-4">
                        <div class="flex items-center gap-2 cursor-pointer" wire:click="toggleSelectAll">
                            <input type="checkbox" 
                                   class="w-4 h-4 rounded border-slate-700 text-cyan-500 focus:ring-0 focus:ring-offset-0 bg-slate-900 cursor-pointer"
                                   {{ count($selectedIds) > 0 && count($selectedIds) >= count($filteredEmails) ? 'checked' : '' }}>
                            <span class="text-[11px] font-medium text-slate-300">Pilih Semua</span>
                        </div>

                        @if(count($selectedIds) > 0)
                        <div class="flex items-center gap-2 animate-fade-in pl-2 border-l border-slate-800">
                            <span class="text-[11px] font-semibold text-cyan-300 font-mono">{{ count($selectedIds) }} dipilih</span>
                            <button wire:click="deleteSelectedMultiple" class="px-2 py-1 rounded-lg bg-rose-500/10 hover:bg-rose-500/20 text-rose-400 border border-rose-500/30 text-[11px] font-semibold flex items-center gap-1 transition-all">
                                <i data-lucide="trash-2" class="w-3 h-3"></i> Hapus
                            </button>
                            <button wire:click="markMultipleRead" class="px-2 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-[11px] font-semibold flex items-center gap-1 transition-all">
                                <i data-lucide="mail-open" class="w-3 h-3"></i> Tandai Dibaca
                            </button>
                        </div>
                        @endif
                    </div>

                    <div class="text-[11px] font-mono text-slate-500">
                        1 - {{ count($filteredEmails) }} dari {{ count($filteredEmails) }}
                    </div>
                </div>

                <!-- Full-Width Email Rows Stream (Gmail / Hostinger Style) -->
                <div class="flex-1 overflow-y-auto divide-y divide-slate-800/40">
                    @forelse($filteredEmails as $email)
                    <div wire:key="email-row-{{ $email['id'] }}"
                         wire:click="selectEmail({{ $email['id'] }})"
                         class="group px-4 sm:px-6 py-3 cursor-pointer transition-colors flex items-center gap-3.5 hover:bg-slate-900/60 {{ $email['is_read'] ? 'bg-slate-950/20 text-slate-300' : 'bg-slate-900/30 text-white font-semibold' }}">
                        
                        <!-- Checkbox & Star (prevent parent click with @click.stop) -->
                        <div class="flex items-center gap-2.5 shrink-0" @click.stop>
                            <input type="checkbox" wire:model.live="selectedIds" value="{{ $email['id'] }}"
                                   class="w-4 h-4 rounded border-slate-700 text-cyan-500 focus:ring-0 focus:ring-offset-0 bg-slate-900 cursor-pointer">
                            <button wire:click="toggleStar({{ $email['id'] }})" class="p-1 text-slate-500 hover:text-amber-400 transition-colors">
                                <i data-lucide="star" class="w-4 h-4 {{ $email['is_starred'] ? 'fill-amber-400 text-amber-400' : '' }}"></i>
                            </button>
                        </div>

                        <!-- Sender / Recipient Name (Jika folder Terkirim, tampilkan To: Penerima) -->
                        <div class="w-28 sm:w-44 md:w-56 shrink-0 truncate text-xs sm:text-sm flex items-center gap-1.5 sm:gap-2">
                            @if(!$email['is_read'])
                                <span class="w-2 h-2 rounded-full bg-cyan-400 shrink-0 ring-4 ring-cyan-400/20" title="Belum Dibaca"></span>
                            @else
                                <span class="w-2 h-2 rounded-full bg-transparent shrink-0"></span>
                            @endif
                            <span class="truncate {{ $email['is_read'] ? 'font-medium text-slate-300' : 'font-bold text-white' }}">
                                @if($activeFolder === 'sent')
                                    <span class="text-slate-400 font-normal">Ke:</span> {{ $email['to'] ?: 'Penerima' }}
                                @else
                                    {{ $email['from_name'] }}
                                @endif
                            </span>
                        </div>

                        <!-- Subject & Body Preview (Full Width Inline) -->
                        <div class="flex-1 min-w-0 flex items-center gap-2 text-xs sm:text-sm truncate pr-2">
                            @if($email['folder'] === 'drafts')
                                <span class="text-[10px] font-bold px-1.5 py-0.5 rounded bg-rose-500/20 text-rose-300 border border-rose-500/30 shrink-0">Draf</span>
                            @endif
                            <span class="truncate {{ $email['is_read'] ? 'text-slate-200 font-normal' : 'text-white font-semibold' }}">
                                {{ $email['subject'] }}
                            </span>
                            @if(($email['thread_count'] ?? 1) > 1)
                                <span class="px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-cyan-500/15 text-cyan-300 border border-cyan-500/25 shrink-0" title="{{ $email['thread_count'] }} balasan dalam percakapan ini">
                                    {{ $email['thread_count'] }}
                                </span>
                            @endif
                            @if(!empty($email['snippet']))
                                <span class="text-slate-500 font-normal truncate hidden md:inline">
                                    - {{ $email['snippet'] }}
                                </span>
                            @endif
                        </div>

                        <!-- Attachments Icon & Date / Hover Action Buttons -->
                        <div class="flex items-center gap-3 shrink-0 text-right">
                            @if(!empty($email['attachments']))
                                <i data-lucide="paperclip" class="w-3.5 h-3.5 text-slate-400" title="Memiliki Lampiran"></i>
                            @endif

                            <!-- Quick Action Buttons on Hover -->
                            <div class="hidden group-hover:flex items-center gap-1" @click.stop>
                                <button wire:click="toggleReadStatus({{ $email['id'] }})" class="p-1.5 rounded-lg hover:bg-slate-800 text-slate-400 hover:text-white" title="{{ $email['is_read'] ? 'Tandai Belum Dibaca' : 'Tandai Sudah Dibaca' }}">
                                    <i data-lucide="{{ $email['is_read'] ? 'mail' : 'mail-open' }}" class="w-3.5 h-3.5"></i>
                                </button>
                                <button wire:click="deleteSingleEmail({{ $email['id'] }})" class="p-1.5 rounded-lg hover:bg-rose-500/20 text-slate-400 hover:text-rose-400" title="{{ $activeFolder === 'trash' ? 'Hapus Permanen' : 'Pindahkan ke Sampah' }}">
                                    <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                </button>
                            </div>

                            <span class="group-hover:hidden text-[11px] text-slate-400 font-medium whitespace-nowrap">
                                {{ $email['date'] }}
                            </span>
                        </div>
                    </div>
                    @empty
                    <div class="p-16 text-center space-y-3">
                        <div class="w-14 h-14 rounded-3xl bg-slate-900 border border-slate-800 flex items-center justify-center mx-auto text-slate-500">
                            <i data-lucide="mail" class="w-7 h-7 stroke-1"></i>
                        </div>
                        <p class="text-sm font-semibold text-slate-300">Tidak ada pesan di folder ini</p>
                        <p class="text-xs text-slate-500">Folder ini kosong atau tidak ada email yang cocok dengan kriteria pencarian.</p>
                    </div>
                    @endforelse
                </div>
            </div>

            @else
            <!-- ========================================== -->
            <!-- 2. MODE DETAIL PESAN (GMAIL / HOSTINGER)    -->
            <!-- ========================================== -->
            <div class="flex-1 flex flex-col min-w-0 h-full overflow-hidden" 
                 wire:key="email-detail-view-{{ $selectedEmailId }}">
                @if($selectedEmail)
                <!-- Hostinger Action Bar (← Back, Mail, Spam, Trash, Move, Summarize AI) -->
                <div class="px-3 sm:px-6 py-2.5 sm:py-3 border-b border-slate-800/80 bg-slate-900/60 flex items-center justify-between gap-2 sm:gap-3 shrink-0">
                    <div class="flex items-center gap-2 sm:gap-3">
                        <!-- Tombol Kembali ← -->
                        <button wire:click="backToList" 
                                class="p-2 rounded-xl text-slate-300 hover:text-white hover:bg-slate-800 transition-colors" 
                                title="Kembali ke Daftar Pesan">
                            <i data-lucide="arrow-left" class="w-4 h-4"></i>
                        </button>

                        <div class="h-4 w-px bg-slate-800"></div>

                        <!-- Icon Action Group: Unread, Spam, Trash, Move -->
                        <div class="flex items-center gap-1 text-slate-400">
                            <button wire:click="toggleReadStatus({{ $selectedEmail['id'] }})" 
                                    class="p-2 rounded-xl hover:text-cyan-300 hover:bg-slate-800 transition-colors" 
                                    title="Tandai Belum Dibaca">
                                <i data-lucide="mail" class="w-4 h-4"></i>
                            </button>

                            <button wire:click="markAsSpam" 
                                    class="p-2 rounded-xl hover:text-amber-400 hover:bg-slate-800 transition-colors" 
                                    title="Laporkan Spam">
                                <i data-lucide="alert-circle" class="w-4 h-4"></i>
                            </button>

                            @if($activeFolder !== 'trash')
                            <button wire:click="deleteSelectedEmail" 
                                    class="p-2 rounded-xl hover:text-rose-400 hover:bg-slate-800 transition-colors" 
                                    title="Pindahkan ke Sampah">
                                <i data-lucide="trash-2" class="w-4 h-4"></i>
                            </button>
                            @else
                            <button wire:click="restoreFromTrash" 
                                    class="px-2.5 py-1 rounded-xl bg-emerald-500/10 text-emerald-400 border border-emerald-500/30 text-xs font-semibold flex items-center gap-1.5" 
                                    title="Kembalikan ke Kotak Masuk">
                                <i data-lucide="archive-restore" class="w-4 h-4"></i>
                                <span>Kembalikan</span>
                            </button>
                            <button wire:click="deleteSelectedEmail" 
                                    class="p-2 rounded-xl text-rose-400 hover:bg-rose-500/20 transition-colors" 
                                    title="Hapus Permanen">
                                <i data-lucide="trash-2" class="w-4 h-4"></i>
                            </button>
                            @endif

                            <button wire:click="moveToFolder('inbox')" 
                                    class="p-2 rounded-xl hover:text-indigo-400 hover:bg-slate-800 transition-colors" 
                                    title="Pindahkan ke Folder">
                                <i data-lucide="folder-input" class="w-4 h-4"></i>
                            </button>
                        </div>

                        <!-- Summarize Button (Hostinger Feature) -->
                        <div class="ml-1">
                            <button type="button" 
                                    @click="alert('Fitur Ringkasan AI: Pesan ini membahas dokumen kontrak, penawaran harga, dan perjanjian kerja sama.')"
                                    class="px-3 py-1.5 rounded-xl border border-indigo-500/40 bg-indigo-500/10 hover:bg-indigo-500/20 text-indigo-300 hover:text-white text-xs font-semibold flex items-center gap-1.5 transition-all shadow-sm">
                                <i data-lucide="sparkles" class="w-3.5 h-3.5 text-indigo-400"></i>
                                <span>Summarize</span>
                            </button>
                        </div>
                    </div>

                    <!-- Right Quick Actions: Bintang, Reply, Forward -->
                    <div class="flex items-center gap-1 text-slate-400">
                        <button wire:click="toggleStar({{ $selectedEmail['id'] }})" 
                                class="p-2 rounded-xl hover:text-amber-400 hover:bg-slate-800 transition-colors" 
                                title="Bintang">
                            <i data-lucide="star" class="w-4 h-4 {{ $selectedEmail['is_starred'] ? 'fill-amber-400 text-amber-400' : '' }}"></i>
                        </button>
                        <button wire:click="replyEmail" 
                                class="p-2 rounded-xl hover:text-white hover:bg-slate-800 transition-colors" 
                                title="Balas Pesan">
                            <i data-lucide="reply" class="w-4 h-4"></i>
                        </button>
                        <button wire:click="forwardEmail" 
                                class="p-2 rounded-xl hover:text-white hover:bg-slate-800 transition-colors" 
                                title="Teruskan Pesan">
                            <i data-lucide="forward" class="w-4 h-4"></i>
                        </button>
                    </div>
                </div>

                <!-- Hostinger Email Content Scroll Area -->
                <div class="flex-1 overflow-y-auto px-4 sm:px-8 lg:px-10 py-4 sm:py-6 space-y-6">
                    
                    <!-- Subject Title (Hostinger bold black/white title) -->
                    <div>
                        <h1 class="text-xl sm:text-2xl font-bold text-white tracking-tight leading-snug">
                            {{ $selectedEmail['subject'] }}
                        </h1>
                    </div>

                    <!-- Sender Info Header Card (Hostinger Style) -->
                    <div class="flex flex-wrap items-start justify-between gap-4 pb-2" x-data="{ showHeaderDetails: false }">
                        <div class="space-y-1 min-w-0">
                            <!-- From line -->
                            <div class="text-sm">
                                <span class="font-bold text-white">From</span>
                                <span class="font-bold text-slate-200 ml-1">{{ $selectedEmail['from_name'] }}</span>
                                <span class="text-xs text-slate-400 font-mono ml-1">&lt;{{ $selectedEmail['from_email'] }}&gt;</span>
                            </div>
                            
                            <!-- To Me Dropdown -->
                            <div class="flex items-center gap-1.5 text-xs text-slate-400">
                                <button @click="showHeaderDetails = !showHeaderDetails" class="hover:text-slate-200 flex items-center gap-1.5 font-medium transition-colors">
                                    <span>to me</span>
                                    @if(!empty($selectedEmail['to']) && $selectedEmail['to'] !== ($currentAccount->email ?? ''))
                                        <span class="px-2 py-0.5 rounded-full bg-cyan-500/15 text-cyan-300 border border-cyan-500/30 text-[10px] font-mono font-medium flex items-center gap-1" title="Diterima via alias alamat email: {{ $selectedEmail['to'] }}">
                                            <i data-lucide="split" class="w-2.5 h-2.5"></i>
                                            <span>via alias: {{ $selectedEmail['to'] }}</span>
                                        </span>
                                    @endif
                                    <i data-lucide="chevron-down" class="w-3.5 h-3.5"></i>
                                </button>
                            </div>

                            <!-- Dropdown details -->
                            <div x-show="showHeaderDetails" x-collapse class="mt-2 p-3 rounded-2xl bg-slate-900 border border-slate-800 text-xs font-mono text-slate-400 space-y-1" style="display: none;">
                                <div><span class="text-slate-500">From:</span> <span class="text-slate-200">{{ $selectedEmail['from_name'] }} &lt;{{ $selectedEmail['from_email'] }}&gt;</span></div>
                                <div><span class="text-slate-500">To:</span> <span class="text-slate-200">{{ $selectedEmail['to'] }}</span></div>
                                <div><span class="text-slate-500">Date:</span> <span class="text-slate-200">{{ $selectedEmail['date'] }}</span></div>
                                <div><span class="text-slate-500">Security:</span> <span class="text-emerald-400">Standard TLS Encryption (Postfix/Dovecot)</span></div>
                            </div>
                        </div>

                        <!-- Right metadata & action icons (Date, Paperclip, Star, Reply, More) -->
                        <div class="flex items-center gap-3 text-slate-400 text-xs shrink-0">
                            <span>{{ $selectedEmail['date'] }}</span>
                            @if(!empty($selectedEmail['attachments']))
                                <i data-lucide="paperclip" class="w-4 h-4 text-slate-400"></i>
                            @endif
                            <button wire:click="toggleStar({{ $selectedEmail['id'] }})" class="hover:text-amber-400 transition-colors">
                                <i data-lucide="star" class="w-4 h-4 {{ $selectedEmail['is_starred'] ? 'fill-amber-400 text-amber-400' : '' }}"></i>
                            </button>
                            <button wire:click="replyEmail" class="hover:text-white transition-colors" title="Balas">
                                <i data-lucide="reply" class="w-4 h-4"></i>
                            </button>
                            <button @click="showHeaderDetails = !showHeaderDetails" class="hover:text-white transition-colors">
                                <i data-lucide="more-vertical" class="w-4 h-4"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Hostinger Attachments Pills / Badges & Live Preview Modal -->
                    @if(!empty($selectedEmail['attachments']))
                    <div class="space-y-3 pt-2 pb-4 border-b border-slate-800/80" x-data="{ previewUrl: null, previewName: '', previewType: '', downloadUrl: null }">
                        <div class="flex flex-wrap items-center gap-3">
                            @foreach($selectedEmail['attachments'] as $att)
                            @php
                                $isObject = is_array($att);
                                $name = $isObject ? ($att['name'] ?? 'Dokumen.pdf') : $att;
                                $size = $isObject ? ($att['size'] ?? '1 MB') : '1.2 MB';
                                $ext = $isObject ? ($att['ext'] ?? pathinfo($name, PATHINFO_EXTENSION)) : pathinfo($name, PATHINFO_EXTENSION);
                                $extLower = strtolower($ext ?: 'dat');
                                $isImage = in_array($extLower, ['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg']);
                                $isPdf = ($extLower === 'pdf');
                                $previewRoute = route('webmail.attachment.preview', ['email' => $selectedEmail['id'], 'index' => $loop->index]);
                                $downloadRoute = route('webmail.attachment.download', ['email' => $selectedEmail['id'], 'index' => $loop->index]);
                            @endphp
                            <div class="flex items-center gap-3 px-3.5 py-2.5 rounded-2xl bg-slate-900 border border-slate-700/80 hover:border-slate-600 shadow-sm transition-all group">
                                <!-- Badge Icon Sesuai Ekstensi File -->
                                <div class="w-8 h-8 rounded-xl {{ $isImage ? 'bg-purple-600 text-white shadow-purple-600/30' : ($isPdf ? 'bg-rose-600 text-white shadow-rose-600/30' : (in_array($extLower, ['doc','docx']) ? 'bg-blue-600 text-white shadow-blue-600/30' : (in_array($extLower, ['xls','xlsx']) ? 'bg-emerald-600 text-white shadow-emerald-600/30' : (in_array($extLower, ['zip','rar','7z','tar','gz']) ? 'bg-amber-600 text-white shadow-amber-600/30' : (in_array($extLower, ['ppt','pptx']) ? 'bg-orange-600 text-white shadow-orange-600/30' : 'bg-slate-700 text-slate-200'))))) }} flex items-center justify-center font-bold text-[10px] tracking-tighter shrink-0 shadow-sm uppercase">
                                    {{ substr($extLower, 0, 4) }}
                                </div>
                                <div class="min-w-0 flex items-center gap-2">
                                    <span class="text-xs font-semibold text-slate-200 truncate max-w-[180px] sm:max-w-[240px]" title="{{ $name }}">
                                        {{ $name }}
                                    </span>
                                    <span class="text-xs text-slate-400 shrink-0 font-medium">
                                        {{ $size }}
                                    </span>
                                </div>

                                <!-- Action Buttons: Preview & Download -->
                                <div class="flex items-center gap-1 ml-1">
                                    <!-- Tombol Preview Interaktif -->
                                    <button type="button" 
                                            @click="previewUrl = '{{ $previewRoute }}'; previewName = '{{ addslashes($name) }}'; previewType = '{{ $isImage ? 'image' : ($isPdf ? 'pdf' : 'generic') }}'; downloadUrl = '{{ $downloadRoute }}'"
                                            class="p-1.5 rounded-lg text-slate-400 hover:text-cyan-300 hover:bg-slate-800 transition-colors" 
                                            title="Lihat Pratinjau {{ $name }}">
                                        <i data-lucide="eye" class="w-4 h-4"></i>
                                    </button>

                                    <!-- Tombol Unduh Riil (Content-Disposition: attachment) -->
                                    <a href="{{ $downloadRoute }}" download="{{ $name }}"
                                       class="p-1.5 rounded-lg text-slate-400 hover:text-emerald-300 hover:bg-slate-800 transition-colors" 
                                       title="Unduh Dokumen {{ $name }}">
                                        <i data-lucide="download" class="w-4 h-4"></i>
                                    </a>
                                </div>
                            </div>
                            @endforeach
                        </div>

                        <!-- Modal Popup Preview Gambar & Dokumen Interaktif -->
                        <div x-show="previewUrl" x-cloak
                             class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/85 backdrop-blur-md"
                             @keydown.escape.window="previewUrl = null" style="display: none;">
                            <div class="relative w-full max-w-4xl max-h-[90vh] bg-slate-900 border border-slate-700/80 rounded-3xl shadow-2xl overflow-hidden flex flex-col"
                                 @click.away="previewUrl = null">
                                <!-- Modal Header -->
                                <div class="px-5 py-3.5 border-b border-slate-800 bg-slate-950/90 flex items-center justify-between gap-3">
                                    <div class="flex items-center gap-2 truncate">
                                        <i data-lucide="file-check" class="w-4 h-4 text-cyan-400 shrink-0"></i>
                                        <span class="text-xs sm:text-sm font-bold text-white truncate" x-text="previewName"></span>
                                    </div>
                                    <div class="flex items-center gap-2 shrink-0">
                                        <a :href="downloadUrl || previewUrl" :download="previewName"
                                           class="px-3 py-1.5 rounded-xl bg-cyan-600 hover:bg-cyan-500 text-white text-xs font-bold flex items-center gap-1.5 transition-all shadow-sm">
                                            <i data-lucide="download" class="w-3.5 h-3.5"></i>
                                            <span>Unduh Dokumen</span>
                                        </a>
                                        <button type="button" @click="previewUrl = null" 
                                                class="p-1.5 rounded-xl bg-slate-800 text-slate-400 hover:text-white transition-colors">
                                            <i data-lucide="x" class="w-4 h-4"></i>
                                        </button>
                                    </div>
                                </div>
                                <!-- Modal Body: Image or PDF Iframe or Generic Document Card -->
                                <div class="flex-1 overflow-auto p-4 flex items-center justify-center bg-slate-950/70 min-h-[350px]">
                                    <template x-if="previewType === 'image'">
                                        <img :src="previewUrl" :alt="previewName" class="max-w-full max-h-[75vh] object-contain rounded-xl shadow-lg border border-slate-800">
                                    </template>
                                    <template x-if="previewType === 'pdf'">
                                        <iframe :src="previewUrl" class="w-full h-[75vh] rounded-xl border border-slate-800"></iframe>
                                    </template>
                                    <template x-if="previewType !== 'image' && previewType !== 'pdf'">
                                        <div class="text-center p-8 space-y-4">
                                            <div class="w-16 h-16 mx-auto rounded-2xl bg-slate-800/90 border border-slate-700 flex items-center justify-center text-cyan-400 shadow-inner">
                                                <i data-lucide="file-text" class="w-8 h-8"></i>
                                            </div>
                                            <div>
                                                <h4 class="text-white font-bold text-base" x-text="previewName"></h4>
                                                <p class="text-xs text-slate-400 mt-1 max-w-sm mx-auto">Pratinjau langsung di dalam browser terbatas untuk tipe dokumen ini. Silakan unduh dokumen untuk membuka di aplikasi komputer Anda.</p>
                                            </div>
                                            <a :href="downloadUrl || previewUrl" :download="previewName"
                                               class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-cyan-600 hover:bg-cyan-500 text-white text-xs font-bold transition-all shadow-lg shadow-cyan-600/30">
                                                <i data-lucide="download" class="w-4 h-4"></i>
                                                <span>Unduh File Lengkap</span>
                                            </a>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>

                        <!-- Attachment Count -->
                        <div class="flex items-center gap-2 text-xs text-slate-400 pt-1">
                            <span>{{ count($selectedEmail['attachments']) }} lampiran file</span>
                            <span>• Klik ikon mata <i data-lucide="eye" class="w-3 h-3 inline text-cyan-400"></i> untuk pratinjau langsung</span>
                        </div>
                    </div>
                    @endif

                    <!-- Warning Banner Saat Berada di Folder SPAM -->
                    @if($activeFolder === 'spam')
                    <div class="p-4 rounded-2xl bg-rose-500/10 border border-rose-500/25 text-xs text-rose-300 space-y-1.5">
                        <div class="flex items-start gap-2.5">
                            <i data-lucide="alert-octagon" class="w-4 h-4 text-rose-400 shrink-0 mt-0.5"></i>
                            <div>
                                <p class="font-bold text-white text-xs">Peringatan Keamanan Spam</p>
                                <p class="text-rose-200/90 leading-relaxed text-[11px] mt-0.5">
                                    {{ $selectedEmail['spam_reason'] ?? 'Pesan ini dilaporkan sebagai spam oleh filter keamanan kami.' }}
                                </p>
                            </div>
                        </div>
                    </div>
                    @endif

                    <!-- Conversation Thread Tree (Rantai Balasan Email / Chat Tree) -->
                    @if(count($threadEmails) > 1)
                    <div class="space-y-4 pt-2 pb-4">
                        <div class="flex items-center gap-2 text-xs font-bold text-slate-400 uppercase tracking-wider pb-1 border-b border-slate-800">
                            <i data-lucide="git-branch" class="w-3.5 h-3.5 text-cyan-400"></i>
                            <span>Rantai Percakapan Email ({{ count($threadEmails) }} Pesan Terkait)</span>
                        </div>

                        <div class="space-y-3 pl-2 sm:pl-3 border-l-2 border-indigo-500/30">
                            @foreach($threadEmails as $index => $tMsg)
                                @if(!$tMsg['is_current'])
                                <div class="rounded-2xl p-4 sm:p-5 bg-slate-900/50 hover:bg-slate-900 border border-slate-800/80 transition-all cursor-pointer space-y-2 group"
                                     wire:click="$set('selectedEmailId', {{ $tMsg['id'] }})">
                                    <div class="flex items-center justify-between gap-3 text-xs">
                                        <div class="flex items-center gap-2">
                                            <span class="w-6 h-6 rounded-full {{ $tMsg['folder'] === 'sent' ? 'bg-cyan-500/20 text-cyan-300' : 'bg-indigo-500/20 text-indigo-300' }} flex items-center justify-center font-bold text-[10px]">
                                                {{ $index + 1 }}
                                            </span>
                                            <span class="font-bold text-white group-hover:text-cyan-400 transition-colors">
                                                {{ $tMsg['from_name'] }}
                                            </span>
                                            <span class="text-[11px] text-slate-500 font-mono">
                                                &lt;{{ $tMsg['from_email'] }}&gt;
                                            </span>
                                            @if($tMsg['folder'] === 'sent')
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-cyan-500/10 text-cyan-300 border border-cyan-500/20">Balasan Anda</span>
                                            @else
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-slate-800 text-slate-300">Pesan Masuk</span>
                                            @endif
                                        </div>
                                        <span class="text-[11px] text-slate-400 font-mono">{{ $tMsg['date'] }}</span>
                                    </div>

                                    <div class="text-xs text-slate-300 line-clamp-2 pl-8 font-sans leading-relaxed opacity-85">
                                        {{ strip_tags($tMsg['body']) }}
                                    </div>
                                </div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                    @endif

                    <!-- Active Email Body Content (HTML & Plain Text Support) -->
                    <div class="py-2 text-slate-200">
                        @if (preg_match('/<[a-z][\s\S]*>/i', $selectedEmail['body']))
                            {{-- Email Berformat Rich HTML (Tampilan Kertas Dokumen Modern Bersih) --}}
                            <div class="rounded-2xl p-6 sm:p-8 bg-slate-900/90 border border-slate-800 text-slate-200 leading-relaxed font-sans shadow-lg overflow-x-auto selection:bg-indigo-500 selection:text-white email-rendered-content">
                                {!! $this->cleanEmailHtml($selectedEmail['body']) !!}
                            </div>
                        @else
                            {{-- Email Berformat Plain Text --}}
                            <div class="rounded-2xl p-6 sm:p-8 bg-slate-900/60 border border-slate-800 text-sm sm:text-base text-slate-200 leading-relaxed font-sans whitespace-pre-line shadow-sm">
                                {{ $selectedEmail['body'] }}
                            </div>
                        @endif
                    </div>

                    <!-- Space filler so content never overlaps with sticky footer -->
                    <div class="h-6"></div>
                </div>

                <!-- Hostinger Flat Sticky Bottom Footer (Reply & Forward Bar / Inline Compose Box) -->
                <div class="border-t border-slate-800/90 bg-slate-950/95 backdrop-blur-xl px-6 sm:px-8 py-4 sm:py-5 shrink-0 z-20 shadow-2xl">
                    @if(!$showInlineReply)
                    <!-- Flat Reply / Forward Rounded Buttons (Tidak berpindah & flat di footer) -->
                    <div class="flex items-center gap-3">
                        <button type="button" 
                                wire:click="replyEmail"
                                class="px-5 py-2 rounded-full border border-slate-700 bg-slate-900/90 hover:bg-slate-800 hover:border-slate-600 text-slate-200 hover:text-white text-xs font-semibold flex items-center gap-2 transition-all shadow-sm">
                            <i data-lucide="reply" class="w-3.5 h-3.5 text-slate-300"></i>
                            <span>Reply</span>
                        </button>

                        <button type="button" 
                                wire:click="forwardEmail"
                                class="px-5 py-2 rounded-full border border-slate-700 bg-slate-900/90 hover:bg-slate-800 hover:border-slate-600 text-slate-200 hover:text-white text-xs font-semibold flex items-center gap-2 transition-all shadow-sm">
                            <i data-lucide="forward" class="w-3.5 h-3.5 text-slate-300"></i>
                            <span>Forward</span>
                        </button>
                    </div>
                    @else
                    <!-- Hostinger Inline Reply Box (Sesuai Gambar Screenshot) -->
                    <div class="rounded-2xl sm:rounded-3xl border border-slate-700/80 bg-slate-900/95 shadow-2xl p-4 sm:p-5 flex flex-col space-y-3 max-h-[46vh] overflow-y-auto custom-scrollbar">
                        <!-- Top Header: Forward vs Reply layout -->
                        @if($inlineReplyMode === 'forward')
                        <!-- Hostinger Forward Header (Arrow, To input, Cc/Bcc/Subject toggle) -->
                        <div class="space-y-2 pb-1 border-b border-slate-800/60">
                            <div class="flex items-center justify-between gap-3">
                                <div class="flex-1 flex items-center gap-2.5 min-w-0">
                                    <i data-lucide="forward" class="w-4 h-4 text-slate-400 shrink-0"></i>
                                    <span class="text-xs font-semibold text-slate-300 shrink-0">To</span>
                                    <input type="email" 
                                           wire:model="quickReplyTo" 
                                           placeholder="Masukkan email penerima..." 
                                           autofocus
                                           class="w-full bg-transparent border-0 text-xs sm:text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-0 p-0 font-sans">
                                </div>
                                <div class="flex items-center gap-3 shrink-0">
                                    <button type="button" 
                                            wire:click="$toggle('showInlineCcBcc')" 
                                            class="text-[11px] text-slate-400 hover:text-cyan-300 font-medium transition-colors">
                                        {{ $showInlineCcBcc ? 'Sembunyikan' : 'Cc / Bcc / Subject' }}
                                    </button>
                                    <button type="button" 
                                            wire:click="closeInlineReply" 
                                            class="p-1 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition-colors" 
                                            title="Tutup Teruskan">
                                        <i data-lucide="x" class="w-4 h-4"></i>
                                    </button>
                                </div>
                            </div>
                            @error('quickReplyTo') <span class="text-xs text-rose-400 block font-medium">{{ $message }}</span> @enderror

                            <!-- Optional Expandable CC/BCC & Subject for Forward -->
                            @if($showInlineCcBcc)
                            <div class="pt-2 space-y-2 border-t border-slate-800/40">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                    <input type="email" wire:model="quickReplyCc" placeholder="Cc: alamat@domain.com" 
                                           class="w-full px-3 py-1.5 bg-slate-950/80 border border-slate-800 rounded-xl text-xs text-white placeholder-slate-500 focus:outline-none focus:border-cyan-500">
                                    <input type="email" wire:model="quickReplyBcc" placeholder="Bcc: alamat@domain.com" 
                                           class="w-full px-3 py-1.5 bg-slate-950/80 border border-slate-800 rounded-xl text-xs text-white placeholder-slate-500 focus:outline-none focus:border-cyan-500">
                                </div>
                                <input type="text" wire:model="quickReplySubject" placeholder="Subject..." 
                                       class="w-full px-3 py-1.5 bg-slate-950/80 border border-slate-800 rounded-xl text-xs text-white placeholder-slate-500 focus:outline-none focus:border-cyan-500">
                            </div>
                            @endif
                        </div>
                        @else
                        <!-- Hostinger Reply Header (Reply arrow, Recipient info, Close icon) -->
                        <div class="flex items-center justify-between pb-1 border-b border-slate-800/60">
                            <div class="flex items-center gap-2 text-xs">
                                <i data-lucide="reply" class="w-4 h-4 text-slate-400"></i>
                                <span class="font-bold text-white">{{ $selectedEmail['from_name'] }}</span>
                                <span class="text-[11px] text-slate-400 font-mono hidden sm:inline">&lt;{{ $selectedEmail['from_email'] }}&gt;</span>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <button type="button" 
                                        wire:click="closeInlineReply" 
                                        class="p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition-colors" 
                                        title="Tutup Balasan">
                                    <i data-lucide="x" class="w-4 h-4"></i>
                                </button>
                            </div>
                        </div>
                        @endif

                        <!-- Pilihan Pengirim (Akun Utama atau Alias) -->
                        @if(!empty($availableAliases) && count($availableAliases) > 0)
                        <div class="flex items-center gap-2 text-xs py-1 px-1 bg-slate-950/40 rounded-xl border border-slate-800/50">
                            <span class="text-slate-400 font-medium shrink-0 text-[11px] pl-1">Balas sebagai:</span>
                            <select wire:model="quickReplyFrom" class="bg-slate-900 border border-slate-700/80 rounded-lg text-xs text-cyan-300 font-semibold px-2.5 py-1 focus:outline-none focus:border-cyan-500 shadow-sm">
                                <option value="{{ $currentAccount->email ?? '' }}">{{ $currentAccount->email ?? '' }} (Utama)</option>
                                @foreach($availableAliases as $alias)
                                    @if($alias !== ($currentAccount->email ?? ''))
                                        <option value="{{ $alias }}">{{ $alias }} (Alias)</option>
                                    @endif
                                @endforeach
                            </select>
                        </div>
                        @endif

                        <!-- Textarea Area -->
                        <div class="relative">
                            <textarea wire:model="quickReplyText" 
                                      rows="{{ $inlineReplyMode === 'forward' ? '4' : '2' }}" 
                                      placeholder="{{ $inlineReplyMode === 'forward' ? 'Tambahkan pesan pengantar di sini...' : 'Tulis balasan email Anda di sini...' }}" 
                                      class="w-full bg-transparent border-0 text-xs sm:text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-0 p-0 resize-y font-sans leading-relaxed"></textarea>
                            @error('quickReplyText') <span class="text-xs text-rose-400 mt-1 block font-medium">{{ $message }}</span> @enderror
                        </div>

                        <!-- AI Suggestion Chips (Hanya saat mode Balas/Reply) -->
                        @if($inlineReplyMode === 'reply')
                        <div class="flex items-center gap-2 overflow-x-auto pb-1 text-[11px] text-slate-300 custom-scrollbar">
                            <button type="button" 
                                    wire:click="$set('quickReplyText', 'Baik, konfirmasi penerimaan dokumen sudah kami catat dan segera kami proses.')"
                                    class="px-3 py-1 rounded-full bg-slate-800/80 hover:bg-slate-700/80 border border-slate-700 text-slate-300 hover:text-white shrink-0 flex items-center gap-1.5 transition-all">
                                <i data-lucide="sparkles" class="w-3 h-3 text-indigo-400"></i>
                                <span>Konfirmasi penerimaan dan tindakan dokumen</span>
                            </button>
                            <button type="button" 
                                    wire:click="$set('quickReplyText', 'Dokumen siap ditandatangani. Mohon info tenggat waktu pengembalian berkas.')"
                                    class="px-3 py-1 rounded-full bg-slate-800/80 hover:bg-slate-700/80 border border-slate-700 text-slate-300 hover:text-white shrink-0 flex items-center gap-1.5 transition-all">
                                <i data-lucide="sparkles" class="w-3 h-3 text-indigo-400"></i>
                                <span>Konfirmasi dokumen siap ditandatangani</span>
                            </button>
                            <button type="button" 
                                    wire:click="$set('quickReplyText', 'Mohon klarifikasi singkat terkait beberapa butir pasal pada kontrak.')"
                                    class="px-3 py-1 rounded-full bg-slate-800/80 hover:bg-slate-700/80 border border-slate-700 text-slate-300 hover:text-white shrink-0 flex items-center gap-1.5 transition-all">
                                <i data-lucide="sparkles" class="w-3 h-3 text-indigo-400"></i>
                                <span>Minta klarifikasi singkat</span>
                            </button>
                        </div>
                        @endif

                        <!-- Ask AI to draft a message Pill Input (Gaya Hostinger) -->
                        <div class="flex items-center justify-between px-3.5 py-1.5 rounded-full border border-indigo-500/40 bg-indigo-950/20 shadow-inner">
                            <div class="flex items-center gap-2.5 text-xs text-indigo-300">
                                <i data-lucide="sparkles" class="w-3.5 h-3.5 text-indigo-400"></i>
                                <span class="font-medium text-slate-300">Ask AI to draft a message</span>
                            </div>
                            <button type="button" 
                                    wire:click="$set('quickReplyText', 'Yth. ' . $selectedEmail['from_name'] . ',\n\nTerima kasih atas kiriman dokumennya. Seluruh berkas telah kami terima dalam kondisi baik dan sedang dalam proses peninjauan oleh tim legal kami.\n\nSalam,\nAdministrator')"
                                    class="w-6 h-6 rounded-full bg-cyan-500/20 hover:bg-cyan-500/30 text-cyan-300 flex items-center justify-center transition-colors">
                                <i data-lucide="arrow-up" class="w-3.5 h-3.5"></i>
                            </button>
                        </div>

                        <!-- Attached Files Badge if any -->
                        @if(!empty($quickReplyAttachments))
                        <div class="flex flex-wrap gap-2 pt-1">
                            @foreach($quickReplyAttachments as $index => $file)
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-xl bg-slate-900 border border-slate-700 text-xs text-cyan-300 font-mono shadow-sm">
                                <i data-lucide="file-check" class="w-3.5 h-3.5 text-emerald-400 shrink-0"></i>
                                <span class="max-w-[150px] truncate">{{ method_exists($file, 'getClientOriginalName') ? $file->getClientOriginalName() : 'File' }}</span>
                                <button type="button" 
                                        wire:click="removeQuickReplyAttachment({{ $index }})" 
                                        class="p-0.5 rounded-full hover:bg-rose-500/20 text-slate-400 hover:text-rose-400 transition-colors" 
                                        title="Batal lampirkan file ini">
                                    <i data-lucide="x" class="w-3 h-3"></i>
                                </button>
                            </span>
                            @endforeach
                        </div>
                        @endif

                        <!-- Bottom Action Bar (Send Pill Button, Formatting Icons, Save draft, Trash) -->
                        <div class="flex items-center justify-between pt-2 border-t border-slate-800/80">
                            <!-- Left: Send button + Formatting Icons -->
                            <div class="flex items-center gap-3">
                                <!-- Hostinger Send Pill Button with Dropdown arrow -->
                                <button type="button" 
                                        wire:click="sendQuickReply" 
                                        class="px-5 py-2 rounded-full bg-indigo-600 hover:bg-indigo-500 text-white font-bold text-xs shadow-md shadow-indigo-600/30 flex items-center gap-2 transition-all hover:scale-102">
                                    <span wire:loading.remove wire:target="sendQuickReply">Send</span>
                                    <span wire:loading wire:target="sendQuickReply">Sending...</span>
                                    <i data-lucide="chevron-down" class="w-3 h-3 opacity-70"></i>
                                </button>

                                <!-- Editor Icons (Pen, Font, Paperclip, Link, Image, Eye) -->
                                <div class="hidden sm:flex items-center gap-1 text-slate-400">
                                    <button type="button" class="p-1.5 hover:text-white hover:bg-slate-800 rounded-lg transition-colors" title="Formatting">
                                        <i data-lucide="pen-tool" class="w-3.5 h-3.5"></i>
                                    </button>
                                    <button type="button" class="p-1.5 hover:text-white hover:bg-slate-800 rounded-lg transition-colors" title="Font Style">
                                        <i data-lucide="type" class="w-3.5 h-3.5"></i>
                                    </button>
                                    <label class="p-1.5 hover:text-white hover:bg-slate-800 rounded-lg transition-colors cursor-pointer" title="Lampirkan Dokumen (Semua Format: PDF, DOC, XLS, ZIP, RAR, Gambar, dll)">
                                        <i data-lucide="paperclip" class="w-3.5 h-3.5"></i>
                                        <input type="file" wire:model="quickReplyAttachments" multiple accept="*/*" onclick="this.value=null" class="hidden">
                                    </label>
                                    <button type="button" class="p-1.5 hover:text-white hover:bg-slate-800 rounded-lg transition-colors" title="Sisipkan Link">
                                        <i data-lucide="link" class="w-3.5 h-3.5"></i>
                                    </button>
                                    <label class="p-1.5 hover:text-white hover:bg-slate-800 rounded-lg transition-colors cursor-pointer" title="Sisipkan Gambar (PNG, JPG, WebP, GIF)">
                                        <i data-lucide="image" class="w-3.5 h-3.5"></i>
                                        <input type="file" wire:model="quickReplyAttachments" multiple accept="*/*" onclick="this.value=null" class="hidden">
                                    </label>

                                </div>
                            </div>

                            <!-- Right: Save Draft & Trash Discard -->
                            <div class="flex items-center gap-3 text-xs">
                                <button type="button" 
                                        wire:click="closeInlineReply" 
                                        class="text-slate-400 hover:text-slate-200 font-medium transition-colors">
                                    Save draft
                                </button>
                                <button type="button" 
                                        wire:click="closeInlineReply" 
                                        class="p-1.5 text-slate-400 hover:text-rose-400 rounded-lg transition-colors" 
                                        title="Buang balasan">
                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    @endif
                </div>
            </div>
            @else
            <div class="flex-1 flex flex-col items-center justify-center text-slate-500 text-xs space-y-3">
                <div class="w-14 h-14 rounded-3xl bg-slate-900/60 border border-slate-800 flex items-center justify-center text-slate-500">
                    <i data-lucide="mail-open" class="w-7 h-7 stroke-1 text-slate-400"></i>
                </div>
                <div class="text-center">
                    <p class="font-bold text-sm text-slate-300">Belum Ada Pesan yang Dipilih</p>
                    <p class="text-slate-500 text-xs mt-1">Pilih salah satu pesan di daftar sebelah kiri untuk membaca</p>
                </div>
            </div>
            @endif
        </div>
        @endif
    </div>

    <!-- Modal Tulis Pesan (Compose) Lengkap dengan CC/BCC -->
    @if($showComposeModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 backdrop-blur-md p-3 sm:p-4 md:p-6 overflow-hidden">
        <div class="w-full max-w-2xl max-h-[92vh] sm:max-h-[88vh] bg-slate-900/98 border border-slate-700/90 rounded-2xl sm:rounded-3xl shadow-2xl overflow-hidden flex flex-col backdrop-blur-2xl">
            <!-- Modal Header -->
            <div class="px-5 sm:px-6 py-3.5 border-b border-slate-800/90 flex items-center justify-between bg-slate-950/80 shrink-0">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-xl bg-gradient-to-tr from-cyan-500 to-indigo-600 flex items-center justify-center text-white shadow-md shadow-indigo-600/25">
                        <i data-lucide="send" class="w-4 h-4"></i>
                    </div>
                    <div>
                        <h4 class="text-sm font-bold text-white flex items-center gap-2">
                            {{ $editingDraftId ? 'Edit Draf Pesan' : 'Tulis Pesan Baru' }}
                            @if($editingDraftId)
                                <span class="text-[9px] font-mono px-2 py-0.5 rounded-full bg-rose-500/20 text-rose-300 border border-rose-500/30 font-bold">DRAFT</span>
                            @endif
                        </h4>
                        <span class="text-[10px] text-slate-500">Tersimpan otomatis ke folder Drafts</span>
                    </div>
                </div>
                <button type="button" wire:click="closeComposeModal" class="p-1.5 rounded-xl text-slate-400 hover:text-white hover:bg-slate-800 transition-colors" title="Simpan ke Draf & Tutup">
                    <i data-lucide="x" class="w-4 h-4"></i>
                </button>
            </div>

            <!-- Form Content with Scrollable Body & Sticky Footer -->
            <form wire:submit="sendEmail" class="flex-1 min-h-0 flex flex-col overflow-hidden">
                <div class="flex-1 overflow-y-auto p-5 sm:p-6 space-y-4 custom-scrollbar">
                    @if(!empty($availableAliases) && count($availableAliases) > 0)
                    <div>
                        <label class="block text-xs font-bold text-slate-300 mb-1.5 flex items-center justify-between">
                            <span>Kirim Sebagai (From Address)</span>
                            <span class="text-[10px] text-cyan-400 font-mono font-medium">Akun / Alias</span>
                        </label>
                        <select wire:model="composeFrom" 
                                class="w-full px-4 py-2.5 bg-slate-950/90 border border-slate-700/80 rounded-xl text-xs text-white focus:outline-none focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500 shadow-inner">
                            <option value="{{ $currentAccount->email ?? '' }}">{{ $currentAccount->email ?? '' }} (Akun Utama)</option>
                            @foreach($availableAliases as $alias)
                                @if($alias !== ($currentAccount->email ?? ''))
                                    <option value="{{ $alias }}">{{ $alias }} (Alias Resmi)</option>
                                @endif
                            @endforeach
                        </select>
                    </div>
                    @endif

                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <label class="block text-xs font-bold text-slate-300">Kepada (To)</label>
                            <button type="button" wire:click="$toggle('showCcBcc')" class="text-[11px] text-cyan-400 hover:text-cyan-300 font-semibold cursor-pointer">
                                {{ $showCcBcc ? 'Sembunyikan CC/BCC' : '+ Tambah CC / BCC' }}
                            </button>
                        </div>
                        <input type="email" 
                               list="compose-saved-contacts"
                               wire:model="composeTo" 
                               placeholder="alamat@tujuan.com (Pilih dari Kontak atau ketik baru)" 
                               class="w-full px-4 py-2.5 bg-slate-950/90 border border-slate-700/80 rounded-xl text-xs text-white placeholder-slate-500 focus:outline-none focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500 shadow-inner">
                        <datalist id="compose-saved-contacts">
                            @foreach($savedContactsList as $sc)
                                <option value="{{ $sc->email }}">{{ $sc->name ? $sc->name . ' (' . $sc->email . ')' : $sc->email }}</option>
                            @endforeach
                        </datalist>
                        @error('composeTo') <span class="text-xs text-rose-400 mt-1 block font-medium">{{ $message }}</span> @enderror
                    </div>

                    @if($showCcBcc)
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-slate-300 mb-1">CC</label>
                            <input type="email" wire:model="composeCc" placeholder="cc@domain.com" 
                                   class="w-full px-4 py-2 bg-slate-950/90 border border-slate-700/80 rounded-xl text-xs text-white placeholder-slate-500 focus:outline-none focus:border-cyan-500">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-300 mb-1">BCC</label>
                            <input type="email" wire:model="composeBcc" placeholder="bcc@domain.com" 
                                   class="w-full px-4 py-2 bg-slate-950/90 border border-slate-700/80 rounded-xl text-xs text-white placeholder-slate-500 focus:outline-none focus:border-cyan-500">
                        </div>
                    </div>
                    @endif

                    <div>
                        <label class="block text-xs font-bold text-slate-300 mb-1.5">Subjek Pesan</label>
                        <input type="text" wire:model="composeSubject" placeholder="Subjek email..." 
                               class="w-full px-4 py-2.5 bg-slate-950/90 border border-slate-700/80 rounded-xl text-xs text-white placeholder-slate-500 focus:outline-none focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500 shadow-inner">
                        @error('composeSubject') <span class="text-xs text-rose-400 mt-1 block font-medium">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-300 mb-1.5">Isi Pesan</label>
                        <textarea rows="6" wire:model="composeBody" placeholder="Tulis isi pesan email Anda di sini..." 
                                  class="w-full min-h-[140px] px-4 py-3 bg-slate-950/90 border border-slate-700/80 rounded-2xl text-xs sm:text-sm text-white placeholder-slate-500 focus:outline-none focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500 font-sans shadow-inner resize-y"></textarea>
                    </div>

                    <!-- File Attachment Field -->
                    <div>
                        <div class="flex items-center justify-between">
                            <label class="cursor-pointer inline-flex items-center gap-2 text-xs text-cyan-400 hover:text-cyan-300 font-semibold py-1.5 px-3 rounded-xl bg-slate-950 border border-slate-800 hover:border-cyan-500/50 transition-all">
                                <i data-lucide="paperclip" class="w-4 h-4"></i>
                                <span>Lampirkan Dokumen (Semua Format: PDF, DOC, XLS, ZIP, RAR, Gambar, dll)</span>
                                <input type="file" wire:model="attachments" multiple accept="*/*" onclick="this.value=null" class="hidden">
                            </label>
                            <span wire:loading wire:target="attachments" class="text-[11px] text-amber-400 animate-pulse">Mengunggah file...</span>
                        </div>
                        @error('attachments') <span class="text-xs text-rose-400 mt-1 block font-medium">{{ $message }}</span> @enderror
                        @error('attachments.*') <span class="text-xs text-rose-400 mt-1 block font-medium">{{ $message }}</span> @enderror

                        @if(!empty($attachments))
                        <div class="flex flex-wrap gap-2 mt-2.5">
                            @foreach($attachments as $index => $file)
                            <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-xl bg-slate-900 border border-slate-700 text-xs text-cyan-300 font-mono shadow-sm">
                                <i data-lucide="file-check" class="w-3.5 h-3.5 text-emerald-400 shrink-0"></i>
                                <span class="max-w-[180px] truncate">{{ method_exists($file, 'getClientOriginalName') ? $file->getClientOriginalName() : 'File' }}</span>
                                <button type="button" 
                                        wire:click="removeAttachment({{ $index }})" 
                                        class="p-0.5 rounded-full hover:bg-rose-500/20 text-slate-400 hover:text-rose-400 transition-colors" 
                                        title="Batal lampirkan file ini">
                                    <i data-lucide="x" class="w-3.5 h-3.5"></i>
                                </button>
                            </span>
                            @endforeach
                        </div>
                        @endif
                    </div>
                </div>

                <!-- Sticky Footer Toolbar -->
                <div class="px-5 sm:px-6 py-3.5 bg-slate-950/95 border-t border-slate-800 flex items-center justify-between gap-3 shrink-0">
                    <div class="flex items-center gap-2">
                        <button type="button" wire:click="discardDraft" 
                                class="p-2.5 rounded-xl text-slate-400 hover:text-rose-400 hover:bg-rose-500/10 transition-colors" 
                                title="Buang draf ini">
                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                        </button>
                        <button type="button" wire:click="saveDraftNow" 
                                class="px-3.5 sm:px-4 py-2 rounded-xl bg-slate-800/90 hover:bg-slate-750 text-slate-300 text-xs font-semibold flex items-center gap-2 transition-all border border-slate-700/60">
                            <i data-lucide="save" class="w-3.5 h-3.5"></i>
                            <span>Simpan Draf</span>
                        </button>
                    </div>

                    <div class="flex items-center gap-2 sm:gap-3">
                        <button type="button" wire:click="closeComposeModal" class="px-3 sm:px-4 py-2 text-xs font-medium text-slate-400 hover:text-white transition-colors">
                            Batal
                        </button>
                        <button type="submit" class="px-5 sm:px-6 py-2.5 bg-gradient-to-r from-cyan-600 to-indigo-600 hover:from-cyan-500 hover:to-indigo-500 text-white font-bold text-xs rounded-xl transition-all shadow-lg shadow-cyan-600/25 flex items-center gap-2 hover:scale-[1.02] active:scale-[0.98]">
                            <i data-lucide="send" class="w-3.5 h-3.5"></i>
                            <span wire:loading.remove wire:target="sendEmail">Kirim Pesan</span>
                            <span wire:loading wire:target="sendEmail">Mengirim via Postfix...</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    @endif

    <!-- Modal Buat Folder Baru -->
    @if($showCreateFolderModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/75 backdrop-blur-md p-4">
        <div class="w-full max-w-sm bg-slate-900/95 border border-slate-700/80 rounded-3xl shadow-2xl overflow-hidden backdrop-blur-xl">
            <div class="px-5 py-4 border-b border-slate-800 flex items-center justify-between bg-slate-950/70">
                <h4 class="text-sm font-bold text-white flex items-center gap-2">
                    <i data-lucide="folder-plus" class="w-4 h-4 text-cyan-400"></i>
                    Buat Folder Kustom Baru
                </h4>
                <button wire:click="$set('showCreateFolderModal', false)" class="text-slate-400 hover:text-white p-1 rounded-lg hover:bg-slate-800">
                    <i data-lucide="x" class="w-4 h-4"></i>
                </button>
            </div>

            <form wire:submit="createFolder" class="p-5 space-y-4 text-xs">
                <div>
                    <label class="block font-bold text-slate-300 mb-1.5">Nama Folder</label>
                    <input type="text" wire:model="newFolderName" placeholder="contoh: Arsip Proyek, Pajak, Klien VIP" 
                           class="w-full px-4 py-2.5 bg-slate-950/90 border border-slate-700/80 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500">
                    @error('newFolderName') <span class="text-xs text-rose-400 mt-1 block font-medium">{{ $message }}</span> @enderror
                </div>

                <div class="flex items-center justify-end gap-2.5 pt-3 border-t border-slate-800">
                    <button type="button" wire:click="$set('showCreateFolderModal', false)" class="px-4 py-2 text-slate-400 hover:text-white font-medium">
                        Batal
                    </button>
                    <button type="submit" class="px-5 py-2.5 bg-gradient-to-r from-cyan-600 to-indigo-600 hover:from-cyan-500 hover:to-indigo-500 text-white font-bold rounded-xl shadow-lg shadow-cyan-600/25 transition-all">
                        Simpan Folder
                    </button>
                </div>
            </form>
        </div>
    </div>
    @endif

    <!-- ============================================================== -->
    <!-- MODAL PENGATURAN USER (PENGATURAN NAMA, PASSWORD, INFO AKUN)  -->
    <!-- ============================================================== -->
    @if($showSettingsModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4 bg-slate-950/85 backdrop-blur-md animate-fade-in"
         wire:key="user-settings-modal">
        <div class="w-full max-w-lg bg-slate-900 border border-slate-700/80 rounded-3xl shadow-2xl overflow-hidden flex flex-col max-h-[90vh] animate-scale-up border-glow">
            
            <!-- Modal Header -->
            <div class="px-6 py-4 bg-slate-950/90 border-b border-slate-800 flex items-center justify-between shrink-0">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-xl bg-indigo-500/10 border border-indigo-500/30 flex items-center justify-center text-indigo-400">
                        <i data-lucide="settings" class="w-4 h-4"></i>
                    </div>
                    <div>
                        <h3 class="text-sm font-bold text-white tracking-wide">Pengaturan Akun Pengguna</h3>
                        <p class="text-[11px] text-slate-400">Kelola profil display nama dan keamanan kata sandi email Anda</p>
                    </div>
                </div>
                <button type="button" wire:click="closeSettingsModal" class="p-2 rounded-xl text-slate-400 hover:text-white hover:bg-slate-800 transition-colors" title="Tutup">
                    <i data-lucide="x" class="w-4 h-4"></i>
                </button>
            </div>

            <!-- Tab Navigasi: Profil vs Ganti Password -->
            <div class="px-6 pt-3 border-b border-slate-800/80 bg-slate-950/40 flex items-center gap-4 text-xs font-semibold">
                <button type="button" 
                        wire:click="$set('settingsTab', 'profile')"
                        class="pb-2.5 border-b-2 flex items-center gap-2 transition-all {{ $settingsTab === 'profile' ? 'border-cyan-400 text-cyan-300' : 'border-transparent text-slate-400 hover:text-slate-200' }}">
                    <i data-lucide="user" class="w-3.5 h-3.5"></i>
                    <span>Informasi Profil</span>
                </button>
                <button type="button" 
                        wire:click="$set('settingsTab', 'password')"
                        class="pb-2.5 border-b-2 flex items-center gap-2 transition-all {{ $settingsTab === 'password' ? 'border-cyan-400 text-cyan-300' : 'border-transparent text-slate-400 hover:text-slate-200' }}">
                    <i data-lucide="key-round" class="w-3.5 h-3.5"></i>
                    <span>Ganti Kata Sandi</span>
                </button>
            </div>

            <!-- Modal Content Body -->
            <div class="p-6 overflow-y-auto space-y-4">
                @if (session()->has('settings_success'))
                    <div class="p-3 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs flex items-center gap-2 shadow-sm">
                        <i data-lucide="check-circle-2" class="w-4 h-4 shrink-0"></i>
                        <span>{{ session('settings_success') }}</span>
                    </div>
                @endif

                <!-- TAB 1: INFORMASI PROFIL & NAMA LENGKAP -->
                @if($settingsTab === 'profile')
                <form wire:submit.prevent="updateProfile" class="space-y-4 text-xs">
                    <div>
                        <label class="block font-semibold text-slate-300 mb-1.5">Alamat Email (Akun Tetap)</label>
                        <div class="px-4 py-2.5 bg-slate-950/60 border border-slate-800 rounded-2xl text-slate-400 font-mono flex items-center justify-between">
                            <span>{{ $currentAccount->email ?? '-' }}</span>
                            <span class="text-[10px] text-emerald-400 font-sans font-medium bg-emerald-500/10 px-2 py-0.5 rounded-full border border-emerald-500/20">Aktif</span>
                        </div>
                        <p class="text-[10px] text-slate-500 mt-1">Alamat email dikelola oleh Administrator server.</p>
                    </div>

                    <div>
                        <label class="block font-semibold text-slate-300 mb-1.5">
                            Nama Tampilan (Display Name) <span class="text-rose-400">*</span>
                        </label>
                        <input type="text" wire:model="settingsName" placeholder="Contoh: Budi Santoso, S.Kom" 
                               class="w-full px-4 py-2.5 bg-slate-950/90 border border-slate-700/80 rounded-2xl text-white placeholder-slate-500 focus:outline-none focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500">
                        @error('settingsName') <span class="text-xs text-rose-400 mt-1 block font-medium">{{ $message }}</span> @enderror
                        <p class="text-[10px] text-slate-500 mt-1">Nama ini akan terlihat oleh penerima saat Anda mengirimkan email keluar.</p>
                    </div>

                    <!-- Detail Tambahan Akun Mailbox -->
                    <div class="p-3.5 rounded-2xl bg-slate-950/70 border border-slate-800 space-y-2 text-[11px] text-slate-400">
                        <div class="flex items-center justify-between">
                            <span class="text-slate-400">Alokasi Kuota Mailbox:</span>
                            <span class="text-white font-mono font-medium">{{ $currentAccount ? $currentAccount->formatted_quota : '-' }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-slate-400">Penyimpanan Terpakai:</span>
                            <span class="text-cyan-300 font-mono font-medium">{{ $currentAccount ? $currentAccount->formatted_used : '-' }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-slate-400">Protokol Mail Server:</span>
                            <span class="text-slate-300 font-mono">IMAP (Port 993) / SMTP (587)</span>
                        </div>
                    </div>

                    <div class="pt-3 border-t border-slate-800 flex items-center justify-end gap-2.5">
                        <button type="button" wire:click="closeSettingsModal" class="px-4 py-2 text-slate-400 hover:text-white font-medium">
                            Tutup
                        </button>
                        <button type="submit" class="px-5 py-2.5 bg-gradient-to-r from-cyan-600 to-indigo-600 hover:from-cyan-500 hover:to-indigo-500 text-white font-bold rounded-xl shadow-lg shadow-cyan-600/25 transition-all flex items-center gap-2">
                            <i data-lucide="save" class="w-3.5 h-3.5"></i>
                            <span wire:loading.remove wire:target="updateProfile">Simpan Profil</span>
                            <span wire:loading wire:target="updateProfile">Menyimpan...</span>
                        </button>
                    </div>
                </form>
                @endif

                <!-- TAB 2: GANTI PASSWORD USER -->
                @if($settingsTab === 'password')
                <form wire:submit.prevent="updatePassword" class="space-y-4 text-xs">
                    <div>
                        <label class="block font-semibold text-slate-300 mb-1.5">
                            Kata Sandi Saat Ini <span class="text-rose-400">*</span>
                        </label>
                        <input type="password" wire:model="settingsCurrentPassword" placeholder="Masukkan password sekarang" 
                               class="w-full px-4 py-2.5 bg-slate-950/90 border border-slate-700/80 rounded-2xl text-white placeholder-slate-500 focus:outline-none focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500 font-mono">
                        @error('settingsCurrentPassword') <span class="text-xs text-rose-400 mt-1 block font-medium">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block font-semibold text-slate-300 mb-1.5">
                            Kata Sandi Baru <span class="text-rose-400">*</span>
                        </label>
                        <input type="password" wire:model="settingsNewPassword" placeholder="Minimal 6 karakter" 
                               class="w-full px-4 py-2.5 bg-slate-950/90 border border-slate-700/80 rounded-2xl text-white placeholder-slate-500 focus:outline-none focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500 font-mono">
                        @error('settingsNewPassword') <span class="text-xs text-rose-400 mt-1 block font-medium">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block font-semibold text-slate-300 mb-1.5">
                            Konfirmasi Kata Sandi Baru <span class="text-rose-400">*</span>
                        </label>
                        <input type="password" wire:model="settingsNewPasswordConfirmation" placeholder="Ulangi password baru" 
                               class="w-full px-4 py-2.5 bg-slate-950/90 border border-slate-700/80 rounded-2xl text-white placeholder-slate-500 focus:outline-none focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500 font-mono">
                    </div>

                    <div class="pt-3 border-t border-slate-800 flex items-center justify-end gap-2.5">
                        <button type="button" wire:click="closeSettingsModal" class="px-4 py-2 text-slate-400 hover:text-white font-medium">
                            Batal
                        </button>
                        <button type="submit" class="px-5 py-2.5 bg-gradient-to-r from-cyan-600 to-indigo-600 hover:from-cyan-500 hover:to-indigo-500 text-white font-bold rounded-xl shadow-lg shadow-cyan-600/25 transition-all flex items-center gap-2">
                            <i data-lucide="key" class="w-3.5 h-3.5"></i>
                            <span wire:loading.remove wire:target="updatePassword">Perbarui Password</span>
                            <span wire:loading wire:target="updatePassword">Memproses...</span>
                        </button>
                    </div>
                </form>
                @endif
            </div>
        </div>
    </div>
    @endif

    <!-- Modal Tambah / Edit Kontak (Address Book) -->
    @if($showContactModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 backdrop-blur-md p-4 overflow-y-auto">
        <div class="w-full max-w-md bg-slate-900 border border-slate-700/80 rounded-3xl shadow-2xl overflow-hidden backdrop-blur-2xl my-auto">
            <!-- Modal Header -->
            <div class="px-6 py-4 border-b border-slate-800 flex items-center justify-between bg-slate-950/80">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-xl bg-gradient-to-tr from-emerald-500 to-teal-600 flex items-center justify-center text-white shadow-md shadow-emerald-600/25">
                        <i data-lucide="user-plus" class="w-4 h-4"></i>
                    </div>
                    <div>
                        <h4 class="text-sm font-bold text-white">
                            {{ $editingContactId ? 'Edit Kontak' : 'Tambah Kontak Baru' }}
                        </h4>
                        <span class="text-[10px] text-slate-500">Tersimpan ke buku kontak mailbox</span>
                    </div>
                </div>
                <button type="button" wire:click="closeContactModal" class="p-1.5 rounded-xl text-slate-400 hover:text-white hover:bg-slate-800 transition-colors cursor-pointer">
                    <i data-lucide="x" class="w-4 h-4"></i>
                </button>
            </div>

            <!-- Modal Body -->
            <form wire:submit.prevent="saveContact" class="p-6 space-y-4 text-xs">
                <div>
                    <label class="block font-semibold text-slate-300 mb-1.5">
                        Nama Lengkap / Panggilan
                    </label>
                    <input type="text" 
                           wire:model="contactName" 
                           placeholder="Contoh: Budi Santoso" 
                           class="w-full px-4 py-2.5 bg-slate-950/90 border border-slate-700/80 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-inner">
                    @error('contactName') <span class="text-xs text-rose-400 mt-1 block font-medium">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block font-semibold text-slate-300 mb-1.5">
                        Alamat Email <span class="text-rose-400">*</span>
                    </label>
                    <input type="email" 
                           wire:model="contactEmail" 
                           placeholder="budi@perusahaan.com" 
                           class="w-full px-4 py-2.5 bg-slate-950/90 border border-slate-700/80 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-inner font-mono">
                    @error('contactEmail') <span class="text-xs text-rose-400 mt-1 block font-medium">{{ $message }}</span> @enderror
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block font-semibold text-slate-300 mb-1.5">
                            Nomor Telepon / WhatsApp
                        </label>
                        <input type="text" 
                               wire:model="contactPhone" 
                               placeholder="081234567890" 
                               class="w-full px-3.5 py-2 bg-slate-950/90 border border-slate-700/80 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-inner font-mono">
                        @error('contactPhone') <span class="text-xs text-rose-400 mt-1 block font-medium">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block font-semibold text-slate-300 mb-1.5">
                            Perusahaan / Instansi
                        </label>
                        <input type="text" 
                               wire:model="contactCompany" 
                               placeholder="PT Solusi Digital" 
                               class="w-full px-3.5 py-2 bg-slate-950/90 border border-slate-700/80 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-inner">
                        @error('contactCompany') <span class="text-xs text-rose-400 mt-1 block font-medium">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div>
                    <label class="block font-semibold text-slate-300 mb-1.5">
                        Catatan Tambahan
                    </label>
                    <textarea wire:model="contactNotes" 
                              rows="2" 
                              placeholder="Catatan kecil mengenai kontak ini..." 
                              class="w-full px-4 py-2 bg-slate-950/90 border border-slate-700/80 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-inner resize-none"></textarea>
                    @error('contactNotes') <span class="text-xs text-rose-400 mt-1 block font-medium">{{ $message }}</span> @enderror
                </div>

                <!-- Footer Buttons -->
                <div class="pt-3 border-t border-slate-800 flex items-center justify-end gap-2.5">
                    <button type="button" 
                            wire:click="closeContactModal" 
                            class="px-4 py-2 text-slate-400 hover:text-white font-medium cursor-pointer">
                        Batal
                    </button>
                    <button type="submit" 
                            class="px-5 py-2.5 bg-gradient-to-r from-emerald-500 to-teal-600 hover:from-emerald-400 hover:to-teal-500 text-white font-bold rounded-xl shadow-lg shadow-emerald-600/25 transition-all flex items-center gap-2 cursor-pointer">
                        <i data-lucide="check" class="w-3.5 h-3.5"></i>
                        <span wire:loading.remove wire:target="saveContact">{{ $editingContactId ? 'Simpan Perubahan' : 'Simpan Kontak' }}</span>
                        <span wire:loading wire:target="saveContact">Menyimpan...</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
    @endif
</div>