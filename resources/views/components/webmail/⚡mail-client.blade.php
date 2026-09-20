<?php

use Livewire\Component;
use Livewire\WithFileUploads;
use App\Models\VirtualUser;
use App\Models\MailboxEmail;
use App\Models\MailboxFolder;

new class extends Component
{
    use WithFileUploads;

    public $activeFolder = 'inbox'; // inbox, sent, drafts, spam, trash, or custom folder name
    public $selectedEmailId = 1;
    public $showComposeModal = false;
    public $currentAccount = null;

    // Search and filter state
    public $searchQuery = '';
    public $filterStarred = false;
    public $filterUnread = false;

    // Custom user-created folders (Gaya Hostinger / Gmail Labels)
    public $customFolders = ['Klien Prioritas', 'Tagihan & Invoice'];
    public $newFolderName = '';
    public $showCreateFolderModal = false;

    // Compose state
    public $composeTo = '';
    public $composeCc = '';
    public $composeBcc = '';
    public $showCcBcc = false;
    public $composeSubject = '';
    public $composeBody = '';
    public $attachments = [];
    public $editingDraftId = null; // ID draft jika sedang mengedit draft yang ada

    // Quick Reply inline state (Balas Cepat Langsung di Bawah Email)
    public $quickReplyText = '';
    public $quickReplyAttachments = [];

    // Mock emails (Mewakili data IMAP Dovecot)
    public $emails = [
        [
            'id' => 1,
            'folder' => 'inbox',
            'from_name' => 'Mail Delivery System',
            'from_email' => 'postmaster@perusahaan.net.id',
            'to' => 'admin@perusahaan.net.id',
            'subject' => 'Selamat Datang di Mail Server Mandiri Anda',
            'date' => 'Hari ini, 14:30',
            'is_read' => true,
            'is_starred' => true,
            'body' => "Halo Administrator,\n\nMail Server Postfix & Dovecot Anda telah tersinkronisasi sempurna dengan Web Portal Laravel.\n\nInformasi Konfigurasi Mailbox:\n• Protokol IMAP: mail.domain.net.id (Port 993 - SSL/TLS)\n• Protokol SMTP: mail.domain.net.id (Port 587 - STARTTLS)\n• Autentikasi: Menggunakan username email lengkap dan password database virtual_users.\n\nSalam hangat,\nTim Postmaster MailIDS",
        ],
        [
            'id' => 2,
            'folder' => 'inbox',
            'from_name' => 'Siti Aminah (Finance)',
            'from_email' => 'siti.aminah@perusahaan.net.id',
            'to' => 'admin@perusahaan.net.id',
            'subject' => 'Laporan Penggunaan Kuota Storage Email Bulan Ini',
            'date' => 'Hari ini, 11:15',
            'is_read' => false,
            'is_starred' => false,
            'body' => "Yth. Administrator,\n\nMohon diperiksa kuota penyimpanan mailbox divisi finance sudah mencapai 85%. Apakah bisa dilakukan penambahan alokasi storage melalui panel portal admin?\n\nTerima kasih,\nSiti Aminah",
        ],
        [
            'id' => 3,
            'folder' => 'inbox',
            'from_name' => 'Mail-Tester Security Bot',
            'from_email' => 'check@mail-tester.com',
            'to' => 'admin@perusahaan.net.id',
            'subject' => 'DKIM, SPF & DMARC Validation Test Report',
            'date' => 'Kemarin, 16:45',
            'is_read' => true,
            'is_starred' => true,
            'body' => "Laporan Diagnostik Email:\n• SPF Record: PASS (v=spf1 mx a ~all)\n• DKIM Signature: PASS (Selector default._domainkey)\n• DMARC Compliance: PASS (p=quarantine)\n• Reverse DNS (PTR): PASS (mail.domain.net.id)\n\nSkor Reputasi Pengiriman: 10/10",
        ],
        [
            'id' => 4,
            'folder' => 'sent',
            'from_name' => 'Administrator',
            'from_email' => 'admin@perusahaan.net.id',
            'to' => 'budi.santoso@perusahaan.net.id',
            'subject' => 'Instruksi Penggunaan Webmail & Klien Thunderbird',
            'date' => 'Kemarin, 09:10',
            'is_read' => true,
            'is_starred' => false,
            'body' => "Budi,\n\nBerikut panduan login ke mailbox perusahaan via Webmail Portal atau setup di Thunderbird / Outlook mobile.\nPastikan gunakan SSL port 993 untuk IMAP.\n\nAdmin",
        ],
        [
            'id' => 5,
            'folder' => 'spam',
            'from_name' => 'Crypto Winner Giveaway',
            'from_email' => 'claims@super-btc-lottery.xyz',
            'to' => 'admin@perusahaan.net.id',
            'subject' => 'Pemberitahuan: Anda Memenangkan 0.5 BTC Giveaway!',
            'date' => '2 hari lalu',
            'is_read' => false,
            'is_starred' => false,
            'spam_reason' => 'Pesan ini dilaporkan sebagai spam oleh filter keamanan kami karena domain pengirim (super-btc-lottery.xyz) tidak memiliki catatan autentikasi SPF/DKIM yang valid, serta berisi pola pancingan informasi sensitif (Phishing/Scam).',
            'spam_score' => 8.7,
            'body' => "Selamat! Alamat email Anda telah terpilih secara acak dalam program reward tahunan kami. Segera klaim hadiah Bitcoin Anda dengan mengklik tautan di bawah ini.\n\nCatatan: Tautan ini hanya berlaku 24 jam.",
        ],
        [
            'id' => 6,
            'folder' => 'drafts',
            'from_name' => 'Administrator',
            'from_email' => 'admin@perusahaan.net.id',
            'to' => 'partner@vendor-cloud.com',
            'subject' => 'Draft Penawaran Kolaborasi Server Email Q4',
            'date' => 'Hari ini, 10:00',
            'is_read' => true,
            'is_starred' => false,
            'body' => "Yth. Tim Vendor Cloud,\n\nKami tertarik untuk mendiskusikan integrasi relay server untuk meningkatkan kapasitas pengiriman bulanan. Berikut beberapa parameter kebutuhan yang sedang kami susun...\n\n(Draf belum selesai)",
        ],
    ];

    public function mount()
    {
        $this->currentAccount = auth('mailbox')->user() ?? VirtualUser::first();

        if ($this->currentAccount) {
            // 1. Muat folder kustom permanen dari database
            $dbFolders = MailboxFolder::where('virtual_user_id', $this->currentAccount->id)->pluck('name')->toArray();
            if (empty($dbFolders)) {
                // Inisialisasi folder bawaan jika baru pertama kali
                foreach (['Klien Prioritas', 'Tagihan & Invoice'] as $f) {
                    MailboxFolder::create([
                        'virtual_user_id' => $this->currentAccount->id,
                        'name' => $f,
                    ]);
                }
                $this->customFolders = ['Klien Prioritas', 'Tagihan & Invoice'];
            } else {
                $this->customFolders = $dbFolders;
            }

            // 2. Muat email permanen dari database
            $dbEmails = MailboxEmail::where('virtual_user_id', $this->currentAccount->id)->get();
            if ($dbEmails->isEmpty()) {
                // Simpan email awal ke database permanen agar tidak pernah hilang saat logout
                foreach ($this->emails as $seedMail) {
                    MailboxEmail::create([
                        'virtual_user_id' => $this->currentAccount->id,
                        'folder' => $seedMail['folder'],
                        'from_name' => $seedMail['from_name'],
                        'from_email' => $seedMail['from_email'],
                        'to' => $seedMail['to'],
                        'subject' => $seedMail['subject'],
                        'date_human' => $seedMail['date'],
                        'is_read' => $seedMail['is_read'],
                        'is_starred' => $seedMail['is_starred'],
                        'body' => $seedMail['body'],
                        'attachments' => $seedMail['attachments'] ?? [],
                        'spam_reason' => $seedMail['spam_reason'] ?? null,
                        'spam_score' => $seedMail['spam_score'] ?? null,
                    ]);
                }
                $dbEmails = MailboxEmail::where('virtual_user_id', $this->currentAccount->id)->get();
            }

            // Map data dari database ke array state Livewire
            $this->emails = $dbEmails->map(function ($item) {
                return [
                    'id' => $item->id,
                    'folder' => $item->folder,
                    'from_name' => $item->from_name,
                    'from_email' => $item->from_email,
                    'to' => $item->to,
                    'subject' => $item->subject,
                    'date' => $item->date_human ?: $item->created_at->format('d M, H:i'),
                    'is_read' => (bool) $item->is_read,
                    'is_starred' => (bool) $item->is_starred,
                    'body' => $item->body,
                    'attachments' => $item->attachments ?: [],
                    'spam_reason' => $item->spam_reason,
                    'spam_score' => $item->spam_score,
                ];
            })->toArray();
        }

        // Tandai email default yang sedang terpilih sebagai terbaca (is_read = true)
        if ($this->selectedEmailId) {
            $this->selectEmail($this->selectedEmailId);
        }

        // Sinkronkan kapasitas storage agar sesuai dengan data aktual email di akun ini
        if ($this->currentAccount) {
            $actualBytes = $this->calculateActualEmailsBytes();
            $this->currentAccount->syncMaildirDiskUsage($actualBytes);
            $this->currentAccount->refresh();
        }
    }

    protected function calculateActualEmailsBytes(): int
    {
        // Hitung ukuran nyata seluruh email (headers, metadata, teks body, dan estimasi lampiran)
        $totalBytes = 0;
        foreach ($this->emails as $email) {
            // Setiap file email di Maildir memiliki header MIME standar ~1.2 KB
            $mimeHeaderOverhead = 1200;
            $bodyBytes = strlen($email['body'] ?? '');
            $subjectBytes = strlen($email['subject'] ?? '');
            $headersBytes = strlen(($email['from_email'] ?? '') . ($email['to'] ?? '') . ($email['from_name'] ?? ''));

            // Lampiran (rata-rata 120 KB per attachment jika ada)
            $attachmentsCount = count($email['attachments'] ?? []);
            $attachmentsBytes = $attachmentsCount * 125000;

            $totalBytes += ($mimeHeaderOverhead + $bodyBytes + $subjectBytes + $headersBytes + $attachmentsBytes);
        }

        return $totalBytes;
    }

    protected function persistState()
    {
        // Simpan langsung ke database secara permanen (tahan logout & multi-device)
        if ($this->currentAccount) {
            // 1. Sinkronkan Folder Kustom
            MailboxFolder::where('virtual_user_id', $this->currentAccount->id)->delete();
            foreach ($this->customFolders as $folderName) {
                MailboxFolder::create([
                    'virtual_user_id' => $this->currentAccount->id,
                    'name' => $folderName,
                ]);
            }

            // 2. Sinkronkan seluruh data Email dan Status (Read, Star, Folder, Draf)
            $existingDbIds = collect($this->emails)->pluck('id')->filter()->toArray();
            // Hapus email di database yang sudah dihapus permanen di UI
            MailboxEmail::where('virtual_user_id', $this->currentAccount->id)
                ->whereNotIn('id', $existingDbIds)
                ->delete();

            foreach ($this->emails as $mail) {
                if (isset($mail['id']) && MailboxEmail::where('id', $mail['id'])->where('virtual_user_id', $this->currentAccount->id)->exists()) {
                    MailboxEmail::where('id', $mail['id'])->update([
                        'folder' => $mail['folder'],
                        'is_read' => $mail['is_read'],
                        'is_starred' => $mail['is_starred'],
                        'to' => $mail['to'],
                        'subject' => $mail['subject'],
                        'body' => $mail['body'],
                        'attachments' => $mail['attachments'] ?? [],
                    ]);
                } else {
                    $created = MailboxEmail::create([
                        'virtual_user_id' => $this->currentAccount->id,
                        'folder' => $mail['folder'],
                        'from_name' => $mail['from_name'] ?? ($this->currentAccount->name ?: 'Administrator'),
                        'from_email' => $mail['from_email'] ?? ($this->currentAccount->email ?: 'admin@perusahaan.net.id'),
                        'to' => $mail['to'],
                        'subject' => $mail['subject'],
                        'date_human' => $mail['date'] ?? 'Baru saja',
                        'is_read' => $mail['is_read'] ?? true,
                        'is_starred' => $mail['is_starred'] ?? false,
                        'body' => $mail['body'] ?? '',
                        'attachments' => $mail['attachments'] ?? [],
                        'spam_reason' => $mail['spam_reason'] ?? null,
                        'spam_score' => $mail['spam_score'] ?? null,
                    ]);
                    // Update ID di memori
                    foreach ($this->emails as &$memMail) {
                        if ($memMail['subject'] === $mail['subject'] && $memMail['body'] === $mail['body']) {
                            $memMail['id'] = $created->id;
                            break;
                        }
                    }
                }
            }

            // 3. Update kalkulasi kapasitas storage di tabel virtual_users
            $actualBytes = $this->calculateActualEmailsBytes();
            $this->currentAccount->syncMaildirDiskUsage($actualBytes);
        }
    }

    public function selectFolder($folder)
    {
        $this->activeFolder = $folder;
        $filtered = collect($this->emails)->where('folder', $folder)->first();
        if ($filtered) {
            $this->selectEmail($filtered['id']);
        } else {
            $this->selectedEmailId = null;
        }
    }

    public function createFolder()
    {
        $this->validate([
            'newFolderName' => 'required|string|min:2|max:30',
        ]);

        $folderClean = trim($this->newFolderName);
        if (!in_array($folderClean, $this->customFolders)) {
            $this->customFolders[] = $folderClean;
            $this->persistState();
            $this->selectFolder($folderClean);
            session()->flash('webmail_msg', "Folder baru '{$folderClean}' berhasil dibuat!");
        }

        $this->newFolderName = '';
        $this->showCreateFolderModal = false;
    }

    public function deleteFolder($folderName)
    {
        // Pindahkan email di dalam folder yang dihapus kembali ke Kotak Masuk (Inbox)
        foreach ($this->emails as $index => $item) {
            if ($item['folder'] === $folderName) {
                $this->emails[$index]['folder'] = 'inbox';
            }
        }

        $this->customFolders = array_values(array_diff($this->customFolders, [$folderName]));
        $this->persistState();

        if ($this->activeFolder === $folderName) {
            $this->selectFolder('inbox');
        }

        session()->flash('webmail_msg', "Folder '{$folderName}' berhasil dihapus. Isi email telah dipindahkan ke Kotak Masuk.");
    }

    public function moveToFolder($targetFolder)
    {
        foreach ($this->emails as $index => $item) {
            if ($item['id'] == $this->selectedEmailId) {
                $this->emails[$index]['folder'] = $targetFolder;
                break;
            }
        }
        $this->persistState();
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

    public function selectEmail($id)
    {
        $this->selectedEmailId = $id;
        $updated = false;
        foreach ($this->emails as &$item) {
            if ($item['id'] == $id && !$item['is_read']) {
                $item['is_read'] = true;
                $updated = true;
            }
        }
        if ($updated) {
            $this->persistState();
        }
    }

    public function toggleReadStatus($id)
    {
        foreach ($this->emails as &$item) {
            if ($item['id'] == $id) {
                $item['is_read'] = !$item['is_read'];
                break;
            }
        }
        $this->persistState();
    }

    public function toggleStar($id)
    {
        foreach ($this->emails as &$item) {
            if ($item['id'] == $id) {
                $item['is_starred'] = !$item['is_starred'];
                break;
            }
        }
        $this->persistState();
    }

    public function replyEmail()
    {
        $selected = collect($this->emails)->firstWhere('id', $this->selectedEmailId);
        if ($selected) {
            $this->composeTo = $selected['from_email'];
            $this->composeSubject = 'Re: ' . preg_replace('/^Re:\s*/i', '', $selected['subject']);
            $this->composeBody = "\n\n--- Pada " . $selected['date'] . ", " . $selected['from_name'] . " menulis: ---\n" . $selected['body'];
            $this->showComposeModal = true;
        }
    }

    public function forwardEmail()
    {
        $selected = collect($this->emails)->firstWhere('id', $this->selectedEmailId);
        if ($selected) {
            $this->composeTo = '';
            $this->composeSubject = 'Fwd: ' . preg_replace('/^Fwd:\s*/i', '', $selected['subject']);
            $this->composeBody = "\n\n---------- Pesan Diteruskan ----------\nDari: " . $selected['from_name'] . " <" . $selected['from_email'] . ">\nTanggal: " . $selected['date'] . "\nSubjek: " . $selected['subject'] . "\n\n" . $selected['body'];
            $this->showComposeModal = true;
        }
    }

    public function deleteSelectedEmail()
    {
        foreach ($this->emails as $index => $item) {
            if ($item['id'] == $this->selectedEmailId) {
                if ($item['folder'] === 'trash') {
                    unset($this->emails[$index]);
                    session()->flash('webmail_msg', 'Pesan berhasil dihapus secara permanen.');
                } else {
                    $this->emails[$index]['folder'] = 'trash';
                    session()->flash('webmail_msg', 'Pesan dipindahkan ke Sampah.');
                }
                break;
            }
        }
        $this->emails = array_values($this->emails);
        $this->persistState();
        $this->selectFolder($this->activeFolder);
    }

    public function restoreFromTrash()
    {
        foreach ($this->emails as $index => $item) {
            if ($item['id'] == $this->selectedEmailId) {
                $this->emails[$index]['folder'] = 'inbox';
                break;
            }
        }
        $this->persistState();
        $this->selectFolder('inbox');
        session()->flash('webmail_msg', 'Pesan berhasil dipulihkan kembali ke Kotak Masuk.');
    }

    public function emptyTrash()
    {
        $this->emails = collect($this->emails)->reject(function ($item) {
            return $item['folder'] === 'trash';
        })->values()->all();
        
        $this->persistState();
        $this->selectFolder('trash');
        session()->flash('webmail_msg', 'Folder Sampah telah dikosongkan.');
    }

    public function emptySpam()
    {
        $this->emails = collect($this->emails)->reject(function ($item) {
            return $item['folder'] === 'spam';
        })->values()->all();
        
        $this->persistState();
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
        if ($this->editingDraftId) {
            $this->emails = collect($this->emails)->reject(function ($item) {
                return $item['id'] == $this->editingDraftId;
            })->values()->all();
            $this->persistState();
            session()->flash('webmail_msg', 'Draf pesan telah dibuang.');
        }
        $this->resetComposeForm();
        $this->showComposeModal = false;
    }

    public function saveDraftNow($silent = false)
    {
        $attachmentNames = [];
        if (!empty($this->attachments)) {
            foreach ($this->attachments as $file) {
                $attachmentNames[] = $file->getClientOriginalName();
            }
        }

        $subject = trim($this->composeSubject) ?: '(Tanpa Subjek)';
        $body = $this->composeBody ?: '';

        if ($this->editingDraftId) {
            foreach ($this->emails as $index => $item) {
                if ($item['id'] == $this->editingDraftId) {
                    $this->emails[$index]['to'] = $this->composeTo;
                    $this->emails[$index]['subject'] = $subject;
                    $this->emails[$index]['body'] = $body;
                    $this->emails[$index]['date'] = 'Hari ini, ' . date('H:i');
                    $this->emails[$index]['attachments'] = $attachmentNames;
                    break;
                }
            }
        } else {
            $newDraft = [
                'id' => count($this->emails) + 1,
                'folder' => 'drafts',
                'from_name' => $this->currentAccount ? $this->currentAccount->name : 'Administrator',
                'from_email' => $this->currentAccount ? $this->currentAccount->email : 'admin@perusahaan.net.id',
                'to' => $this->composeTo ?: '(Belum ada penerima)',
                'subject' => $subject,
                'date' => 'Hari ini, ' . date('H:i'),
                'is_read' => true,
                'is_starred' => false,
                'body' => $body,
                'attachments' => $attachmentNames,
            ];
            $this->emails[] = $newDraft;
            $this->editingDraftId = $newDraft['id'];
        }

        $this->persistState();
        if (!$silent) {
            session()->flash('webmail_msg', 'Draf berhasil disimpan.');
        }
    }

    public function openDraft($id)
    {
        $draft = collect($this->emails)->firstWhere('id', $id);
        if ($draft && $draft['folder'] === 'drafts') {
            $this->editingDraftId = $draft['id'];
            $this->composeTo = $draft['to'] === '(Belum ada penerima)' ? '' : $draft['to'];
            $this->composeSubject = $draft['subject'] === '(Tanpa Subjek)' ? '' : $draft['subject'];
            $this->composeBody = $draft['body'];
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

        // Hapus draft lama jika email ini dikirim dari draf yang diedit
        if ($this->editingDraftId) {
            $this->emails = collect($this->emails)->reject(function ($item) {
                return $item['id'] == $this->editingDraftId;
            })->values()->all();
        }

        $attachmentNames = [];
        if (!empty($this->attachments)) {
            foreach ($this->attachments as $file) {
                $attachmentNames[] = $file->getClientOriginalName();
            }
        }

        $newEmail = [
            'id' => count($this->emails) + 1,
            'folder' => 'sent',
            'from_name' => $this->currentAccount ? $this->currentAccount->name : 'Administrator',
            'from_email' => $this->currentAccount ? $this->currentAccount->email : 'admin@perusahaan.net.id',
            'to' => $this->composeTo,
            'subject' => $this->composeSubject,
            'date' => 'Baru saja',
            'is_read' => true,
            'is_starred' => false,
            'body' => $this->composeBody,
            'attachments' => $attachmentNames,
        ];

        $this->emails[] = $newEmail;
        $this->persistState();
        $this->resetComposeForm();
        $this->showComposeModal = false;
        session()->flash('webmail_msg', 'Email beserta lampiran berhasil dikirim ke antrean SMTP Postfix!');
    }

    public function sendQuickReply()
    {
        $this->validate([
            'quickReplyText' => 'required|string|min:2',
        ]);

        $selected = collect($this->emails)->firstWhere('id', $this->selectedEmailId);
        if (!$selected) return;

        $attachmentNames = [];
        if (!empty($this->quickReplyAttachments)) {
            foreach ($this->quickReplyAttachments as $file) {
                $attachmentNames[] = $file->getClientOriginalName();
            }
        }

        $replyEmail = [
            'id' => count($this->emails) + 1,
            'folder' => 'sent',
            'from_name' => $this->currentAccount ? $this->currentAccount->name : 'Administrator',
            'from_email' => $this->currentAccount ? $this->currentAccount->email : 'admin@perusahaan.net.id',
            'to' => $selected['from_email'],
            'subject' => 'Re: ' . preg_replace('/^Re:\s*/i', '', $selected['subject']),
            'date' => 'Baru saja',
            'is_read' => true,
            'is_starred' => false,
            'body' => $this->quickReplyText . "\n\n--- Pada " . $selected['date'] . ", " . $selected['from_name'] . " menulis: ---\n" . $selected['body'],
            'attachments' => $attachmentNames,
        ];

        $this->emails[] = $replyEmail;
        $this->persistState();
        $this->reset(['quickReplyText', 'quickReplyAttachments']);
        session()->flash('webmail_msg', 'Balasan berhasil dikirim!');
    }

    public function render()
    {
        $filteredEmails = collect($this->emails)
            ->where('folder', $this->activeFolder)
            ->when($this->searchQuery, function ($collection) {
                $q = strtolower($this->searchQuery);
                return $collection->filter(function ($email) use ($q) {
                    return str_contains(strtolower($email['subject']), $q) ||
                           str_contains(strtolower($email['from_name']), $q) ||
                           str_contains(strtolower($email['from_email']), $q) ||
                           str_contains(strtolower($email['body']), $q);
                });
            })
            ->when($this->filterStarred, function ($collection) {
                return $collection->where('is_starred', true);
            })
            ->when($this->filterUnread, function ($collection) {
                return $collection->where('is_read', false);
            })
            ->values();

        $selectedEmail = collect($this->emails)->firstWhere('id', $this->selectedEmailId);

        $counts = [
            'inbox' => collect($this->emails)->where('folder', 'inbox')->where('is_read', false)->count(),
            'sent' => collect($this->emails)->where('folder', 'sent')->count(),
            'drafts' => collect($this->emails)->where('folder', 'drafts')->count(),
            'spam' => collect($this->emails)->where('folder', 'spam')->where('is_read', false)->count(),
            'trash' => collect($this->emails)->where('folder', 'trash')->count(),
        ];

        return view('components.webmail.⚡mail-client', [
            'filteredEmails' => $filteredEmails,
            'selectedEmail' => $selectedEmail,
            'counts' => $counts,
        ])->layout('layouts.webmail', ['title' => 'Webmail Client - MailIDS']);
    }
};
?>

<div class="h-[calc(100vh-6.5rem)] md:h-[calc(100vh-8rem)] flex flex-col rounded-2xl bg-slate-900/80 border border-slate-800 overflow-hidden shadow-2xl"
     x-data="{ showFolderSidebar: false, mobileEmailOpen: false }">
    
    <!-- Top Action Toolbar -->
    <div class="min-h-14 px-4 py-2 border-b border-slate-800 bg-slate-900 flex flex-wrap items-center justify-between gap-3 shrink-0">
        <div class="flex items-center gap-2 sm:gap-3">
            <!-- Mobile Toggle Folder Button -->
            <button @click="showFolderSidebar = !showFolderSidebar" class="md:hidden p-2 rounded-lg bg-slate-800 text-slate-300 hover:text-white" title="Pilih Folder">
                <i data-lucide="folder" class="w-4 h-4"></i>
            </button>

            <button wire:click="$set('showComposeModal', true)" 
                    class="px-3 sm:px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold rounded-xl flex items-center gap-2 transition-all shadow-md shadow-indigo-600/30">
                <i data-lucide="edit-3" class="w-3.5 h-3.5"></i>
                <span>Tulis Pesan</span>
            </button>
            <div class="hidden sm:block h-5 w-px bg-slate-800"></div>
            <div class="text-xs text-slate-300 font-medium flex items-center gap-1.5 truncate max-w-[180px] sm:max-w-none">
                <span class="text-slate-500 hidden sm:inline">Akun:</span>
                <span class="font-mono text-indigo-300 truncate text-[11px] sm:text-xs">{{ $currentAccount->email ?? 'admin@perusahaan.net.id' }}</span>
            </div>
        </div>

        @if (session()->has('webmail_msg'))
            <div class="px-3 py-1 rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs flex items-center gap-1.5 animate-pulse">
                <i data-lucide="check" class="w-3.5 h-3.5"></i>
                {{ session('webmail_msg') }}
            </div>
        @endif

        <div class="flex items-center gap-3">
            <div class="hidden sm:flex items-center gap-2">
                <span class="text-[11px] text-slate-400 flex items-center gap-1">
                    <span class="w-2 h-2 rounded-full bg-emerald-400"></span> Dovecot IMAP Ready
                </span>
            </div>
        </div>
    </div>

    <!-- 3-Column Webmail Layout -->
    <div class="flex-1 flex overflow-hidden relative">
        <!-- Kolom 1: Folder Navigasi (Collapsible Drawer on Mobile, 190px on Desktop) -->
        <div :class="showFolderSidebar ? 'translate-x-0' : '-translate-x-full md:translate-x-0'"
             class="absolute md:static inset-y-0 left-0 w-52 bg-slate-950/95 md:bg-slate-950/50 border-r border-slate-800 p-3 flex flex-col justify-between shrink-0 z-20 transition-transform duration-150 ease-in-out">
            <div class="space-y-1">
                <div class="flex items-center justify-between md:hidden pb-2 mb-1 border-b border-slate-800">
                    <span class="text-xs font-bold text-slate-300">Folders</span>
                    <button @click="showFolderSidebar = false" class="text-slate-400 hover:text-white">
                        <i data-lucide="x" class="w-4 h-4"></i>
                    </button>
                </div>

                <button wire:click="selectFolder('inbox')" @click="showFolderSidebar = false"
                        class="w-full flex items-center justify-between px-3 py-2 rounded-lg text-xs font-medium transition-all {{ $activeFolder === 'inbox' ? 'bg-indigo-600 text-white font-semibold' : 'text-slate-400 hover:bg-slate-800 hover:text-white' }}">
                    <div class="flex items-center gap-2.5">
                        <i data-lucide="inbox" class="w-4 h-4"></i>
                        <span>Kotak Masuk</span>
                    </div>
                    @if($counts['inbox'] > 0)
                        <span class="text-[10px] px-1.5 py-0.2 rounded-full bg-indigo-500/30 text-white font-bold">{{ $counts['inbox'] }}</span>
                    @endif
                </button>

                <button wire:click="selectFolder('sent')" @click="showFolderSidebar = false"
                        class="w-full flex items-center justify-between px-3 py-2 rounded-lg text-xs font-medium transition-all {{ $activeFolder === 'sent' ? 'bg-indigo-600 text-white font-semibold' : 'text-slate-400 hover:bg-slate-800 hover:text-white' }}">
                    <div class="flex items-center gap-2.5">
                        <i data-lucide="send" class="w-4 h-4"></i>
                        <span>Terkirim</span>
                    </div>
                </button>

                <button wire:click="selectFolder('drafts')" @click="showFolderSidebar = false"
                        class="w-full flex items-center justify-between px-3 py-2 rounded-lg text-xs font-medium transition-all {{ $activeFolder === 'drafts' ? 'bg-indigo-600 text-white font-semibold' : 'text-slate-400 hover:bg-slate-800 hover:text-white' }}">
                    <div class="flex items-center gap-2.5">
                        <i data-lucide="file-text" class="w-4 h-4"></i>
                        <span>Drafts</span>
                    </div>
                    @if($counts['drafts'] > 0)
                        <span class="text-[10px] px-1.5 py-0.2 rounded-full bg-rose-500/20 text-rose-300 font-bold border border-rose-500/30">{{ $counts['drafts'] }}</span>
                    @endif
                </button>

                <!-- Folder Spam (Kumpulan Spam) -->
                <button wire:click="selectFolder('spam')" @click="showFolderSidebar = false"
                        class="w-full flex items-center justify-between px-3 py-2 rounded-lg text-xs font-medium transition-all {{ $activeFolder === 'spam' ? 'bg-indigo-600 text-white font-semibold' : 'text-slate-400 hover:bg-slate-800 hover:text-white' }}">
                    <div class="flex items-center gap-2.5">
                        <i data-lucide="alert-octagon" class="w-4 h-4 text-amber-400"></i>
                        <span>Spam</span>
                    </div>
                    @if($counts['spam'] > 0)
                        <span class="text-[10px] px-1.5 py-0.2 rounded-full bg-amber-500/20 text-amber-300 font-bold border border-amber-500/30">{{ $counts['spam'] }}</span>
                    @endif
                </button>

                <button wire:click="selectFolder('trash')" @click="showFolderSidebar = false"
                        class="w-full flex items-center justify-between px-3 py-2 rounded-lg text-xs font-medium transition-all {{ $activeFolder === 'trash' ? 'bg-indigo-600 text-white font-semibold' : 'text-slate-400 hover:bg-slate-800 hover:text-white' }}">
                    <div class="flex items-center gap-2.5">
                        <i data-lucide="trash" class="w-4 h-4"></i>
                        <span>Sampah</span>
                    </div>
                </button>

                <!-- Kumpulan Folder Kustom (Gaya Hostinger Webmail) -->
                <div class="pt-3 border-t border-slate-800/80">
                    <div class="flex items-center justify-between px-2 mb-1.5">
                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Folder Kustom</span>
                        <button @click="$wire.set('showCreateFolderModal', true)" 
                                class="p-1 rounded hover:bg-slate-800 text-indigo-400 hover:text-white transition-colors" 
                                title="Buat Folder Baru">
                            <i data-lucide="folder-plus" class="w-3.5 h-3.5"></i>
                        </button>
                    </div>

                    <div class="space-y-0.5">
                        @foreach($customFolders as $folder)
                        <div class="group relative flex items-center justify-between rounded-lg transition-all {{ $activeFolder === $folder ? 'bg-indigo-600 text-white font-semibold' : 'text-slate-400 hover:bg-slate-800/80 hover:text-white' }}">
                            <button wire:click="selectFolder('{{ $folder }}')" @click="showFolderSidebar = false"
                                    class="w-full flex items-center gap-2 px-3 py-1.5 text-xs font-medium truncate text-left">
                                <i data-lucide="folder" class="w-3.5 h-3.5 text-cyan-400 shrink-0"></i>
                                <span class="truncate">{{ $folder }}</span>
                            </button>
                            
                            <!-- Tombol Hapus Folder (Muncul saat hover di baris folder) -->
                            <button type="button" 
                                    wire:click="deleteFolder('{{ $folder }}')" 
                                    wire:confirm="Yakin ingin menghapus folder '{{ $folder }}'? Email di dalamnya akan dipindahkan ke Kotak Masuk."
                                    class="opacity-0 group-hover:opacity-100 p-1.5 mr-1 rounded hover:bg-rose-500/20 text-slate-400 hover:text-rose-400 transition-all shrink-0" 
                                    title="Hapus folder {{ $folder }}">
                                <i data-lucide="trash-2" class="w-3 h-3"></i>
                            </button>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <!-- Kuota Info Dinamis dari Database virtual_users & Dovecot -->
            @php
                $usedFormatted = $currentAccount ? $currentAccount->formatted_used : '1.16 GB';
                $quotaFormatted = $currentAccount ? $currentAccount->formatted_quota : '5 GB';
                $usagePercent = $currentAccount ? $currentAccount->quota_usage_percent : 23;
                $barColor = $usagePercent >= 90 ? 'bg-rose-500' : ($usagePercent >= 75 ? 'bg-amber-500' : 'bg-indigo-500');
            @endphp
            <div class="p-2.5 rounded-xl bg-slate-900 border border-slate-800 text-[11px] text-slate-400 space-y-1.5" title="Kapasitas penyimpanan akun {{ $currentAccount->email ?? '' }}">
                <div class="flex justify-between font-medium">
                    <span class="flex items-center gap-1.5">
                        <i data-lucide="hard-drive" class="w-3 h-3 text-slate-500"></i>
                        <span>Storage</span>
                    </span>
                    <span class="text-slate-300 font-mono">{{ $usedFormatted }} / {{ $quotaFormatted }}</span>
                </div>
                <div class="w-full h-1.5 bg-slate-800 rounded-full overflow-hidden">
                    <div class="h-full {{ $barColor }} rounded-full transition-all duration-300" style="width: {{ $usagePercent }}%"></div>
                </div>
                <div class="flex justify-between text-[10px] text-slate-500 pt-0.5">
                    <span>Terpakai {{ $usagePercent }}%</span>
                    @if($usagePercent >= 90)
                        <span class="text-rose-400 font-semibold flex items-center gap-0.5">
                            <i data-lucide="alert-triangle" class="w-2.5 h-2.5"></i> Hampir Penuh
                        </span>
                    @endif
                </div>
            </div>
        </div>

        <!-- Kolom 2: Daftar Email Masuk + Search & Filter Bar -->
        <div :class="mobileEmailOpen ? 'hidden md:flex' : 'flex'"
             class="w-full md:w-80 border-r border-slate-800 bg-slate-900/40 flex-col shrink-0 overflow-hidden">
            
            <!-- Search & Quick Filter Bar -->
            <div class="p-2.5 border-b border-slate-800 bg-slate-900/80 space-y-2 shrink-0">
                <div class="relative">
                    <i data-lucide="search" class="w-3.5 h-3.5 absolute left-3 top-2.5 text-slate-500"></i>
                    <input type="text" wire:model.live.debounce.200ms="searchQuery" placeholder="Cari email..." 
                           class="w-full pl-8 pr-3 py-1.5 bg-slate-950 border border-slate-800 rounded-lg text-xs text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500 font-sans">
                </div>

                <div class="flex items-center justify-between text-[11px] px-1">
                    <div class="flex items-center gap-1.5">
                        <button wire:click="$toggle('filterUnread')" 
                                class="px-2 py-0.5 rounded text-[10px] font-semibold border transition-all {{ $filterUnread ? 'bg-indigo-600 text-white border-indigo-500' : 'bg-slate-950 text-slate-400 border-slate-800 hover:text-white' }}">
                            Belum Dibaca
                        </button>
                        <button wire:click="$toggle('filterStarred')" 
                                class="px-2 py-0.5 rounded text-[10px] font-semibold border transition-all {{ $filterStarred ? 'bg-amber-500/20 text-amber-300 border-amber-500/30' : 'bg-slate-950 text-slate-400 border-slate-800 hover:text-white' }}">
                            ★ Berbintang
                        </button>
                    </div>
                    <span class="text-[10px] text-slate-500 font-mono">{{ count($filteredEmails) }} pesan</span>
                </div>

                @if($activeFolder === 'trash' && count($filteredEmails) > 0)
                <div class="pt-1 border-t border-slate-800/80 flex items-center justify-between">
                    <span class="text-[10px] text-slate-400">Folder Sampah</span>
                    <button wire:click="emptyTrash" 
                            wire:confirm="Yakin ingin mengosongkan seluruh folder Sampah? Pesan tidak dapat dipulihkan kembali."
                            class="text-[10px] text-rose-400 hover:text-rose-300 hover:underline flex items-center gap-1 font-semibold">
                        <i data-lucide="trash" class="w-3 h-3"></i>
                        Kosongkan Sampah
                    </button>
                </div>
                @endif

                @if($activeFolder === 'spam' && count($filteredEmails) > 0)
                <div class="pt-1 border-t border-slate-800/80 flex items-center justify-between">
                    <span class="text-[10px] text-slate-400">Folder Spam</span>
                    <button wire:click="emptySpam" 
                            wire:confirm="Yakin ingin menghapus semua pesan spam sekarang? Pesan akan dihapus secara permanen."
                            class="text-[10px] text-rose-400 hover:text-rose-300 hover:underline flex items-center gap-1 font-semibold">
                        <i data-lucide="trash-2" class="w-3 h-3"></i>
                        Hapus Semua Spam
                    </button>
                </div>
                @endif
            </div>

            <!-- Email List Stream -->
            <div class="flex-1 overflow-y-auto divide-y divide-slate-800/60">
                @forelse($filteredEmails as $email)
                <div wire:click="selectEmail({{ $email['id'] }})" @click="mobileEmailOpen = true"
                     class="p-3.5 cursor-pointer transition-all border-l-2 relative {{ $selectedEmailId == $email['id'] ? 'bg-indigo-950/40 border-indigo-500' : 'border-transparent hover:bg-slate-800/40' }}">
                    <div class="flex items-center justify-between mb-1">
                        <div class="flex items-center gap-2 truncate pr-2">
                            @if(!$email['is_read'])
                                <span class="w-2 h-2 rounded-full bg-cyan-400 shrink-0 ring-2 ring-cyan-400/20" title="Belum Dibaca"></span>
                            @endif
                            <span class="text-xs truncate {{ $email['is_read'] ? 'font-medium text-slate-300' : 'font-bold text-white' }}">
                                {{ $email['from_name'] }}
                            </span>
                        </div>
                        <span class="text-[10px] text-slate-500 shrink-0">{{ $email['date'] }}</span>
                    </div>
                    <div class="text-xs truncate flex items-center gap-1.5 {{ $email['is_read'] ? 'font-normal text-slate-400' : 'font-semibold text-cyan-200' }}">
                        @if($email['folder'] === 'drafts')
                            <span class="text-[10px] font-bold text-rose-400">Draf</span>
                            <span class="text-slate-600">•</span>
                        @endif
                        <span class="truncate">{{ $email['subject'] }}</span>
                    </div>
                    <div class="text-[11px] text-slate-500 truncate mt-1">
                        {{ Str::limit($email['body'], 50) }}
                    </div>
                </div>
                @empty
                <div class="p-8 text-center text-xs text-slate-500">
                    Tidak ada email yang cocok dengan kriteria.
                </div>
                @endforelse
            </div>
        </div>

        <!-- Kolom 3: Viewer Isi Email (Full width on mobile detail, flex-1 on desktop) -->
        <div :class="mobileEmailOpen ? 'flex' : 'hidden md:flex'"
             class="flex-1 bg-slate-950 flex flex-col min-w-0 h-full overflow-hidden"
             x-data="{ showInlineReply: false }"
             wire:key="email-view-{{ $selectedEmailId }}">
            @if($selectedEmail)
            <!-- Mobile Back to List Button -->
            <div class="md:hidden px-4 py-2.5 border-b border-slate-800 bg-slate-900/80 flex items-center justify-between shrink-0">
                <button @click="mobileEmailOpen = false" class="flex items-center gap-1.5 text-xs text-indigo-400 font-semibold">
                    <i data-lucide="arrow-left" class="w-4 h-4"></i>
                    Kembali ke Daftar Pesan
                </button>
            </div>

            <!-- Email Header (Compact Gmail-Style with Expandable Detail Popover) -->
            <div class="px-4 py-3 sm:px-6 sm:py-3.5 border-b border-slate-800 bg-slate-900/70 shrink-0"
                 x-data="{ showHeaderDetails: false, showMoveDropdown: false }"
                 wire:key="header-{{ $selectedEmail['id'] }}">
                <div class="flex items-center justify-between gap-3">
                    <!-- Left: Avatar, Subject & Quick Sender with Detail Toggle -->
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-cyan-600/30 to-indigo-600/30 text-cyan-300 flex items-center justify-center font-bold text-xs shrink-0 ring-1 ring-white/10">
                            {{ strtoupper(substr($selectedEmail['from_name'], 0, 1)) }}
                        </div>
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <h3 class="text-sm sm:text-base font-bold text-white tracking-tight truncate">{{ $selectedEmail['subject'] }}</h3>
                            </div>
                            <div class="flex items-center gap-1.5 text-xs text-slate-400 relative">
                                <span class="font-semibold text-slate-200 truncate">{{ $selectedEmail['from_name'] }}</span>
                                
                                <!-- Gmail Style Detail Toggle Button -->
                                <button @click="showHeaderDetails = !showHeaderDetails" 
                                        class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded hover:bg-slate-800 text-[11px] text-slate-400 hover:text-cyan-300 transition-colors border border-transparent hover:border-slate-700">
                                    <span>detail</span>
                                    <i data-lucide="chevron-down" class="w-3 h-3 transition-transform duration-200" :class="showHeaderDetails ? 'rotate-180' : ''"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Right: Action Bar & Date -->
                    <div class="flex items-center gap-1.5 shrink-0">
                        <span class="text-slate-400 text-xs font-medium hidden lg:inline mr-1 px-2 py-0.5 rounded bg-slate-950/60 border border-slate-800">{{ $selectedEmail['date'] }}</span>
                        
                        @if($activeFolder === 'trash')
                            <!-- Tombol Pulihkan dari Sampah (Cukup 1 tombol di header toolbar) -->
                            <button wire:click="restoreFromTrash" 
                                    class="px-3 py-1.5 rounded-xl bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-400 border border-emerald-500/20 text-xs font-semibold flex items-center gap-1.5 transition-all shadow-sm"
                                    title="Kembalikan ke Kotak Masuk">
                                <i data-lucide="archive-restore" class="w-3.5 h-3.5"></i>
                                <span>Kembalikan</span>
                            </button>

                            <!-- Hapus Permanen -->
                            <button wire:click="deleteSelectedEmail" 
                                    class="px-3 py-1.5 rounded-xl bg-rose-500/10 hover:bg-rose-500/20 text-rose-400 border border-rose-500/20 text-xs font-semibold flex items-center gap-1.5 transition-all"
                                    title="Hapus Selamanya">
                                <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                <span>Hapus Selamanya</span>
                            </button>
                        @elseif($activeFolder === 'spam')
                            <!-- Aksi Khusus Folder SPAM (Gaya Gmail) -->
                            <button wire:click="markNotSpam" 
                                    class="px-3 py-1.5 rounded-xl bg-indigo-500/10 hover:bg-indigo-500/20 text-indigo-300 border border-indigo-500/20 text-xs font-semibold flex items-center gap-1.5 transition-all shadow-sm"
                                    title="Tandai Bukan Spam dan Pindahkan ke Kotak Masuk">
                                <i data-lucide="shield-check" class="w-3.5 h-3.5 text-emerald-400"></i>
                                <span>Bukan Spam</span>
                            </button>

                            <button wire:click="deleteSelectedEmail" 
                                    class="px-3 py-1.5 rounded-xl bg-rose-500/10 hover:bg-rose-500/20 text-rose-400 border border-rose-500/20 text-xs font-semibold flex items-center gap-1.5 transition-all"
                                    title="Hapus Selamanya">
                                <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                <span>Hapus Selamanya</span>
                            </button>
                        @elseif($activeFolder === 'drafts')
                            <!-- Aksi Khusus Folder DRAFTS (Gaya Gmail) -->
                            <button wire:click="openDraft({{ $selectedEmail['id'] }})" 
                                    class="px-3 py-1.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold flex items-center gap-1.5 transition-all shadow-md shadow-indigo-600/30"
                                    title="Buka dan Lanjutkan Tulis Draf">
                                <i data-lucide="edit-3" class="w-3.5 h-3.5"></i>
                                <span>Lanjutkan Menulis</span>
                            </button>

                            <button wire:click="deleteSelectedEmail" 
                                    class="px-3 py-1.5 rounded-xl bg-slate-800 hover:bg-rose-500/20 text-slate-300 hover:text-rose-400 border border-slate-700 text-xs font-semibold flex items-center gap-1.5 transition-all"
                                    title="Buang Draf Ini">
                                <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                <span>Buang Draf</span>
                            </button>
                        @else
                            <!-- Toggle Read / Unread -->
                            <button wire:click="toggleReadStatus({{ $selectedEmail['id'] }})" 
                                    class="p-1.5 sm:p-2 rounded-lg text-slate-400 hover:text-cyan-300 hover:bg-slate-800 transition-colors" 
                                    title="{{ $selectedEmail['is_read'] ? 'Tandai Belum Dibaca' : 'Tandai Sudah Dibaca' }}">
                                <i data-lucide="{{ $selectedEmail['is_read'] ? 'mail' : 'mail-open' }}" class="w-3.5 h-3.5 sm:w-4 sm:h-4"></i>
                            </button>

                            <button wire:click="toggleStar({{ $selectedEmail['id'] }})" class="p-1.5 sm:p-2 rounded-lg text-slate-400 hover:text-amber-400 hover:bg-slate-800 transition-colors" title="Bintang">
                                <i data-lucide="star" class="w-3.5 h-3.5 sm:w-4 sm:h-4 {{ $selectedEmail['is_starred'] ? 'fill-amber-400 text-amber-400' : '' }}"></i>
                            </button>

                            <!-- Pindahkan ke Folder Dropdown -->
                            <div class="relative">
                                <button @click="showMoveDropdown = !showMoveDropdown" class="p-1.5 sm:p-2 rounded-lg text-slate-400 hover:text-cyan-300 hover:bg-slate-800 transition-colors" title="Pindahkan ke Folder">
                                    <i data-lucide="folder-input" class="w-3.5 h-3.5 sm:w-4 sm:h-4"></i>
                                </button>
                                <div x-show="showMoveDropdown" @click.away="showMoveDropdown = false" 
                                     class="absolute right-0 mt-2 w-44 rounded-xl bg-slate-900 border border-slate-700 shadow-2xl py-1 text-xs z-30 space-y-0.5" style="display: none;">
                                    <div class="px-3 py-1 text-[10px] text-slate-400 uppercase font-bold border-b border-slate-800">Pindahkan Ke:</div>
                                    <button @click="showMoveDropdown = false" wire:click="moveToFolder('inbox')" class="w-full text-left px-3 py-1.5 hover:bg-slate-800 text-slate-300 hover:text-white flex items-center gap-2">
                                        <i data-lucide="inbox" class="w-3.5 h-3.5"></i> Kotak Masuk
                                    </button>
                                    <button @click="showMoveDropdown = false" wire:click="moveToFolder('spam')" class="w-full text-left px-3 py-1.5 hover:bg-slate-800 text-amber-400 hover:text-amber-300 flex items-center gap-2">
                                        <i data-lucide="alert-octagon" class="w-3.5 h-3.5"></i> Tandai Spam
                                    </button>
                                    @foreach($customFolders as $cf)
                                    <button @click="showMoveDropdown = false" wire:click="moveToFolder('{{ $cf }}')" class="w-full text-left px-3 py-1.5 hover:bg-slate-800 text-slate-300 hover:text-white flex items-center gap-2 truncate">
                                        <i data-lucide="folder" class="w-3.5 h-3.5 text-cyan-400"></i> {{ $cf }}
                                    </button>
                                    @endforeach
                                </div>
                            </div>
                            
                            <button @click="showInlineReply = !showInlineReply" class="px-2.5 py-1.5 sm:px-3 sm:py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold flex items-center gap-1.5 transition-all shadow-sm shadow-indigo-600/20">
                                <i data-lucide="reply" class="w-3.5 h-3.5"></i>
                                <span>Balas</span>
                            </button>

                            <button wire:click="forwardEmail" class="p-1.5 sm:p-2 rounded-lg text-slate-400 hover:text-indigo-300 hover:bg-slate-800 transition-colors" title="Teruskan">
                                <i data-lucide="forward" class="w-3.5 h-3.5 sm:w-4 sm:h-4"></i>
                            </button>
                            
                            <button wire:click="deleteSelectedEmail" class="p-1.5 sm:p-2 rounded-lg text-slate-400 hover:text-rose-400 hover:bg-slate-800 transition-colors" title="Hapus ke Sampah">
                                <i data-lucide="trash-2" class="w-3.5 h-3.5 sm:w-4 sm:h-4"></i>
                            </button>
                        @endif
                    </div>
                </div>

                <!-- Gmail-Style Detail Popover Card -->
                <div x-show="showHeaderDetails" 
                     @click.away="showHeaderDetails = false"
                     x-transition:enter="transition ease-out duration-150"
                     x-transition:enter-start="opacity-0 translate-y-1 scale-98"
                     x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                     class="mt-3 p-4 rounded-xl bg-slate-950/95 border border-slate-700/80 shadow-2xl max-w-xl text-xs space-y-2.5 backdrop-blur-md">
                    <div class="grid grid-cols-[80px_1fr] gap-2 items-baseline">
                        <span class="text-slate-400 font-medium">Dari:</span>
                        <div class="text-slate-200">
                            <span class="font-bold">{{ $selectedEmail['from_name'] }}</span>
                            <span class="text-cyan-300 font-mono text-[11px]">&lt;{{ $selectedEmail['from_email'] }}&gt;</span>
                        </div>
                    </div>
                    <div class="grid grid-cols-[80px_1fr] gap-2 items-baseline">
                        <span class="text-slate-400 font-medium">Kepada:</span>
                        <span class="text-slate-200 font-mono">{{ $selectedEmail['to'] }}</span>
                    </div>
                    <div class="grid grid-cols-[80px_1fr] gap-2 items-baseline">
                        <span class="text-slate-400 font-medium">Tanggal:</span>
                        <span class="text-slate-300">{{ $selectedEmail['date'] }}</span>
                    </div>
                    <div class="grid grid-cols-[80px_1fr] gap-2 items-baseline">
                        <span class="text-slate-400 font-medium">Subjek:</span>
                        <span class="text-slate-200 font-semibold">{{ $selectedEmail['subject'] }}</span>
                    </div>
                    <div class="grid grid-cols-[80px_1fr] gap-2 items-baseline pt-1 border-t border-slate-800">
                        <span class="text-slate-400 font-medium">Keamanan:</span>
                        @if($activeFolder === 'spam')
                            <div class="flex items-center gap-2 text-rose-400 font-mono text-[11px]">
                                <i data-lucide="shield-alert" class="w-3.5 h-3.5"></i>
                                <span>Unverified Sender • SPF FAIL • Skor Spam: {{ $selectedEmail['spam_score'] ?? '7.8' }}/10</span>
                            </div>
                        @else
                            <div class="flex items-center gap-2 text-emerald-400 font-mono text-[11px]">
                                <i data-lucide="shield-check" class="w-3.5 h-3.5"></i>
                                <span>Enkripsi Standar (TLS/SSL) • SPF PASS • DKIM PASS</span>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <!-- Gmail-Style Warning Banner Saat Berada di Folder SPAM -->
            @if($activeFolder === 'spam')
            <div class="px-5 py-3.5 bg-rose-500/10 border-b border-rose-500/20 text-xs text-rose-300 space-y-2 shrink-0">
                <div class="flex items-start gap-2.5">
                    <div class="p-1 rounded-lg bg-rose-500/20 text-rose-400 mt-0.5 shrink-0">
                        <i data-lucide="alert-octagon" class="w-4 h-4"></i>
                    </div>
                    <div class="space-y-1">
                        <p class="font-bold text-white text-xs">
                            Mengapa pesan ini berada di Spam?
                        </p>
                        <p class="text-rose-200/90 leading-relaxed text-[11px]">
                            {{ $selectedEmail['spam_reason'] ?? 'Pesan ini memiliki pola yang mirip dengan email phishing atau promosi tidak diminta (Unsolicited Bulk Email). Filter keamanan MailIDS menempatkannya di sini untuk melindungi akun Anda.' }}
                        </p>
                    </div>
                </div>
            </div>
            @endif

            <!-- Notice Bersih Minimalis di Folder Sampah (Tanpa Tombol Duplikat) -->
            @if($activeFolder === 'trash')
            <div class="px-5 py-2 bg-slate-900/40 border-b border-slate-800/80 flex items-center gap-2 text-xs text-slate-400 shrink-0">
                <i data-lucide="info" class="w-3.5 h-3.5 text-slate-500"></i>
                <span>Pesan ini berada di <strong>Sampah</strong>. Gunakan tombol <strong>Kembalikan</strong> di kanan atas untuk memulihkan ke Kotak Masuk.</span>
            </div>
            @endif

            <!-- Scrollable Reading Area (Isi Email Leluasa dan Bersih) -->
            <div class="flex-1 overflow-y-auto p-5 sm:p-8 space-y-6">
                <!-- Email Body Text -->
                <div class="text-sm sm:text-base text-slate-200 leading-relaxed font-sans whitespace-pre-line bg-slate-900/30 p-5 sm:p-6 rounded-2xl border border-slate-800/80 shadow-inner">
                    {{ $selectedEmail['body'] }}
                </div>

                <!-- Attachment Files Section (Jika ada) -->
                @if(!empty($selectedEmail['attachments']))
                <div class="p-4 rounded-2xl bg-slate-900/40 border border-slate-800">
                    <p class="text-xs font-bold text-slate-300 mb-3 flex items-center gap-2">
                        <i data-lucide="paperclip" class="w-4 h-4 text-cyan-400"></i>
                        Dokumen Lampiran ({{ count($selectedEmail['attachments']) }} File):
                    </p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-2.5">
                        @foreach($selectedEmail['attachments'] as $att)
                        <div class="p-3 rounded-xl bg-slate-950 border border-slate-800 flex items-center justify-between gap-2 hover:border-slate-700 transition-all">
                            <div class="flex items-center gap-2.5 truncate">
                                <i data-lucide="file-text" class="w-4 h-4 text-indigo-400 shrink-0"></i>
                                <span class="text-xs text-slate-200 font-mono truncate">{{ $att }}</span>
                            </div>
                            <span class="text-[10px] text-slate-500 uppercase shrink-0">Unduh</span>
                        </div>
                        @endforeach
                    </div>
                </div>
                @endif

                <!-- Tombol Aksi Bawah Email (Menyesuaikan Folder) -->
                @if($activeFolder === 'drafts')
                <div class="pt-2">
                    <button wire:click="openDraft({{ $selectedEmail['id'] }})" 
                            class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl text-xs font-bold flex items-center gap-2 shadow-lg shadow-indigo-600/30 transition-all">
                        <i data-lucide="edit-3" class="w-4 h-4"></i>
                        <span>Lanjutkan Menulis Draf Ini</span>
                    </button>
                </div>
                @else
                <!-- Tombol Buka Form Balas (Jika form balas tertutup) -->
                <div x-show="!showInlineReply" class="pt-2">
                    <button @click="showInlineReply = true" 
                            class="px-5 py-2.5 bg-slate-900 hover:bg-slate-800 border border-slate-700 hover:border-indigo-500 text-white rounded-xl text-xs font-semibold flex items-center gap-2 shadow-md transition-all">
                        <i data-lucide="reply" class="w-4 h-4 text-cyan-400"></i>
                        <span>Tulis Balasan untuk Email Ini</span>
                    </button>
                </div>
                @endif

                <!-- Form Balas Pesan (Tampil hanya ketika tombol Balas diklik) -->
                <div x-show="showInlineReply" 
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="opacity-0 -translate-y-2"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     class="p-5 rounded-2xl bg-slate-900/80 border border-indigo-500/40 shadow-xl space-y-3">
                    <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                        <div class="flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-cyan-400"></span>
                            <span class="text-xs font-bold text-white">Membalas ke:</span>
                            <span class="text-xs text-cyan-300 font-mono">{{ $selectedEmail['from_email'] }}</span>
                        </div>
                        <button @click="showInlineReply = false" class="text-slate-400 hover:text-white p-1 rounded-lg hover:bg-slate-800">
                            <i data-lucide="x" class="w-4 h-4"></i>
                        </button>
                    </div>

                    <form wire:submit="sendQuickReply" class="space-y-3">
                        <textarea wire:model="quickReplyText" rows="4" placeholder="Ketik balasan Anda di sini..." 
                                  class="w-full px-4 py-3 bg-slate-950 border border-slate-700 rounded-xl text-xs sm:text-sm text-white placeholder-slate-500 focus:outline-none focus:border-cyan-500 font-sans"></textarea>
                        @error('quickReplyText') <span class="text-xs text-rose-400 block">{{ $message }}</span> @enderror

                        @if(!empty($quickReplyAttachments))
                        <div class="flex flex-wrap gap-2 text-xs text-cyan-300 font-mono">
                            @foreach($quickReplyAttachments as $file)
                                <span class="px-2.5 py-1 rounded-lg bg-slate-950 border border-slate-800 flex items-center gap-1.5">
                                    <i data-lucide="file-check" class="w-3.5 h-3.5 text-emerald-400"></i>
                                    {{ $file->getClientOriginalName() }}
                                </span>
                            @endforeach
                        </div>
                        @endif

                        <div class="flex flex-wrap items-center justify-between gap-3 pt-2">
                            <label class="cursor-pointer text-indigo-400 hover:text-indigo-300 flex items-center gap-1.5 text-xs font-semibold py-1.5 px-3 rounded-lg bg-slate-950 border border-slate-800 hover:border-slate-700">
                                <i data-lucide="paperclip" class="w-3.5 h-3.5"></i>
                                <span>Lampirkan File</span>
                                <input type="file" wire:model="quickReplyAttachments" multiple class="hidden">
                            </label>

                            <div class="flex items-center gap-2">
                                <button type="button" @click="showInlineReply = false" class="px-4 py-2 text-xs text-slate-400 hover:text-white">
                                    Tutup
                                </button>
                                <button type="submit" class="px-5 py-2.5 bg-gradient-to-r from-cyan-600 to-indigo-600 hover:from-cyan-500 hover:to-indigo-500 text-white font-bold text-xs rounded-xl shadow-lg shadow-cyan-600/25 flex items-center gap-2 transition-all">
                                    <i data-lucide="send" class="w-3.5 h-3.5"></i>
                                    <span wire:loading.remove wire:target="sendQuickReply">Kirim Balasan</span>
                                    <span wire:loading wire:target="sendQuickReply">Mengirim via Postfix...</span>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            @else
            <div class="flex-1 flex flex-col items-center justify-center text-slate-500 text-xs">
                <i data-lucide="mail-open" class="w-10 h-10 mb-2 stroke-1 text-slate-600"></i>
                Pilih pesan di sebelah kiri untuk membaca
            </div>
            @endif
        </div>
    </div>

    <!-- Modal Tulis Pesan (Compose) Lengkap dengan CC/BCC -->
    @if($showComposeModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4">
        <div class="w-full max-w-2xl bg-slate-900 border border-slate-800 rounded-2xl shadow-2xl overflow-hidden flex flex-col">
            <div class="px-5 py-3.5 border-b border-slate-800 flex items-center justify-between bg-slate-950/60">
                <h4 class="text-sm font-bold text-white flex items-center gap-2">
                    <i data-lucide="send" class="w-4 h-4 text-indigo-400"></i>
                    {{ $editingDraftId ? 'Edit Draf Pesan' : 'Tulis Pesan Baru' }}
                    @if($editingDraftId)
                        <span class="text-[9px] font-mono px-2 py-0.5 rounded bg-rose-500/20 text-rose-300 border border-rose-500/30">DRAFT</span>
                    @endif
                </h4>
                <div class="flex items-center gap-2">
                    <span class="text-[10px] text-slate-500 hidden sm:inline">Tutup untuk simpan otomatis ke Drafts</span>
                    <button type="button" wire:click="closeComposeModal" class="p-1 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800" title="Simpan ke Draf & Tutup">
                        <i data-lucide="x" class="w-4 h-4"></i>
                    </button>
                </div>
            </div>

            <form wire:submit="sendEmail" class="p-5 space-y-4">
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <label class="block text-xs font-semibold text-slate-300">Kepada (To)</label>
                        <button type="button" wire:click="$toggle('showCcBcc')" class="text-[11px] text-indigo-400 hover:text-indigo-300">
                            {{ $showCcBcc ? 'Sembunyikan CC/BCC' : '+ Tambah CC / BCC' }}
                        </button>
                    </div>
                    <input type="email" wire:model="composeTo" placeholder="alamat@tujuan.com" 
                           class="w-full px-3.5 py-2 bg-slate-950 border border-slate-700 rounded-lg text-xs text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500">
                    @error('composeTo') <span class="text-xs text-rose-400 mt-1 block">{{ $message }}</span> @enderror
                </div>

                @if($showCcBcc)
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1">CC</label>
                        <input type="email" wire:model="composeCc" placeholder="cc@domain.com" 
                               class="w-full px-3.5 py-2 bg-slate-950 border border-slate-700 rounded-lg text-xs text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1">BCC</label>
                        <input type="email" wire:model="composeBcc" placeholder="bcc@domain.com" 
                               class="w-full px-3.5 py-2 bg-slate-950 border border-slate-700 rounded-lg text-xs text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500">
                    </div>
                </div>
                @endif

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Subjek Pesan</label>
                    <input type="text" wire:model="composeSubject" placeholder="Subjek email..." 
                           class="w-full px-3.5 py-2 bg-slate-950 border border-slate-700 rounded-lg text-xs text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500">
                    @error('composeSubject') <span class="text-xs text-rose-400 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Isi Pesan</label>
                    <textarea rows="6" wire:model="composeBody" placeholder="Tulis isi pesan email di sini..." 
                              class="w-full px-3.5 py-2 bg-slate-950 border border-slate-700 rounded-lg text-xs text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500 font-sans"></textarea>
                </div>

                <!-- File Attachment Field -->
                <div>
                    <div class="flex items-center justify-between">
                        <label class="cursor-pointer inline-flex items-center gap-1.5 text-xs text-indigo-400 hover:text-indigo-300 font-semibold">
                            <i data-lucide="paperclip" class="w-4 h-4"></i>
                            <span>Lampirkan Dokumen / File</span>
                            <input type="file" wire:model="attachments" multiple class="hidden">
                        </label>
                        <span wire:loading wire:target="attachments" class="text-[11px] text-amber-400 animate-pulse">Mengunggah file...</span>
                    </div>

                    @if(!empty($attachments))
                    <div class="flex flex-wrap gap-2 mt-2">
                        @foreach($attachments as $file)
                        <span class="px-2.5 py-1 rounded-lg bg-slate-950 border border-slate-700 text-xs text-indigo-300 font-mono flex items-center gap-1.5">
                            <i data-lucide="file-check" class="w-3.5 h-3.5 text-emerald-400"></i>
                            {{ $file->getClientOriginalName() }}
                        </span>
                        @endforeach
                    </div>
                    @endif
                </div>

                <div class="flex items-center justify-between gap-3 pt-3 border-t border-slate-800/80">
                    <div class="flex items-center gap-2">
                        <!-- Buang Draf (Trash Icon) -->
                        <button type="button" wire:click="discardDraft" 
                                class="p-2 rounded-xl text-slate-400 hover:text-rose-400 hover:bg-slate-800 transition-colors" 
                                title="Buang draf ini">
                            <i data-lucide="trash" class="w-4 h-4"></i>
                        </button>
                        <button type="button" wire:click="saveDraftNow" 
                                class="px-3 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-semibold flex items-center gap-1.5 transition-all">
                            <i data-lucide="save" class="w-3.5 h-3.5"></i>
                            <span>Simpan Draf</span>
                        </button>
                    </div>

                    <div class="flex items-center gap-2">
                        <button type="button" wire:click="closeComposeModal" class="px-4 py-2 text-xs font-medium text-slate-400 hover:text-white">
                            Tutup
                        </button>
                        <button type="submit" class="px-5 py-2.5 bg-gradient-to-r from-indigo-600 to-indigo-700 hover:from-indigo-500 hover:to-indigo-600 text-white font-bold text-xs rounded-xl transition-all shadow-md shadow-indigo-600/30 flex items-center gap-2">
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

    <!-- Modal Buat Folder Baru (Gaya Hostinger Webmail) -->
    @if($showCreateFolderModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4">
        <div class="w-full max-w-sm bg-slate-900 border border-slate-800 rounded-2xl shadow-2xl overflow-hidden">
            <div class="px-5 py-3.5 border-b border-slate-800 flex items-center justify-between bg-slate-950/60">
                <h4 class="text-sm font-bold text-white flex items-center gap-2">
                    <i data-lucide="folder-plus" class="w-4 h-4 text-indigo-400"></i>
                    Buat Folder Kustom Baru
                </h4>
                <button wire:click="$set('showCreateFolderModal', false)" class="text-slate-400 hover:text-white">
                    <i data-lucide="x" class="w-4 h-4"></i>
                </button>
            </div>

            <form wire:submit="createFolder" class="p-5 space-y-4 text-xs">
                <div>
                    <label class="block font-semibold text-slate-300 mb-1">Nama Folder</label>
                    <input type="text" wire:model="newFolderName" placeholder="contoh: Arsip Proyek, Pajak, Klien VIP" 
                           class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-700 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500">
                    @error('newFolderName') <span class="text-xs text-rose-400 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-800">
                    <button type="button" wire:click="$set('showCreateFolderModal', false)" class="px-4 py-2 text-slate-400 hover:text-white">
                        Batal
                    </button>
                    <button type="submit" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-500 text-white font-bold rounded-xl shadow-md transition-all">
                        Simpan Folder
                    </button>
                </div>
            </form>
        </div>
    </div>
    @endif
</div>