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
    public $selectedEmailId = null;
    public $viewMode = 'list'; // 'list' or 'detail' (Gmail/Hostinger style)
    public $activeFilter = 'all'; // 'all', 'unread', 'read', 'starred'
    public $selectedIds = []; // Checkbox select all / multiple
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
            'from_name' => 'Dwifa Ardianty',
            'from_email' => 'dwifa.ardianty@ioh.co.id',
            'to' => 'admin@perusahaan.net.id',
            'subject' => 'FAB , Kontrak dan T&C PT Infra Digital Solusindo',
            'date' => 'Hari ini, 14:30',
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
            'id' => 2,
            'folder' => 'inbox',
            'from_name' => 'Mail Delivery System',
            'from_email' => 'postmaster@perusahaan.net.id',
            'to' => 'admin@perusahaan.net.id',
            'subject' => 'Selamat Datang di Mail Server Mandiri Anda',
            'date' => 'Hari ini, 11:15',
            'is_read' => false,
            'is_starred' => false,
            'body' => "Halo Administrator,\n\nMail Server Postfix & Dovecot Anda telah tersinkronisasi sempurna dengan Web Portal Laravel.\n\nInformasi Konfigurasi Mailbox:\n• Protokol IMAP: mail.domain.net.id (Port 993 - SSL/TLS)\n• Protokol SMTP: mail.domain.net.id (Port 587 - STARTTLS)\n• Autentikasi: Menggunakan username email lengkap dan password database virtual_users.\n\nSalam hangat,\nTim Postmaster MailIDS",
            'attachments' => [
                ['name' => 'Panduan_IMAP_SMTP_Server.pdf', 'size' => '850 KB', 'type' => 'pdf'],
            ],
        ],
        [
            'id' => 3,
            'folder' => 'inbox',
            'from_name' => 'Siti Aminah (Finance)',
            'from_email' => 'siti.aminah@perusahaan.net.id',
            'to' => 'admin@perusahaan.net.id',
            'subject' => 'Laporan Penggunaan Kuota Storage Email Bulan Ini',
            'date' => 'Kemarin, 16:45',
            'is_read' => true,
            'is_starred' => false,
            'body' => "Yth. Administrator,\n\nMohon diperiksa kuota penyimpanan mailbox divisi finance sudah mencapai 85%. Apakah bisa dilakukan penambahan alokasi storage melalui panel portal admin?\n\nTerima kasih,\nSiti Aminah",
            'attachments' => [
                ['name' => 'Laporan_Finance_Storage_Q3.pdf', 'size' => '1.2 MB', 'type' => 'pdf'],
            ],
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
            'attachments' => [],
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
            'attachments' => [],
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
            'attachments' => [],
        ],
    ];

    public function mount()
    {
        $this->currentAccount = auth('mailbox')->user() ?? VirtualUser::first();

        if ($this->currentAccount) {
            // 1. Muat folder kustom permanen dari database
            $dbFolders = MailboxFolder::where('virtual_user_id', $this->currentAccount->id)->pluck('name')->toArray();
            if (empty($dbFolders)) {
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

            // 2. Muat email dari database
            $this->loadEmailsFromDatabase();
        }

        // Sinkronkan kapasitas storage agar sesuai dengan data aktual email di akun ini
        if ($this->currentAccount) {
            $actualBytes = $this->calculateActualEmailsBytes();
            $this->currentAccount->syncMaildirDiskUsage($actualBytes);
            $this->currentAccount->refresh();
        }
    }

    public function refreshInbox()
    {
        // Segarkan status kotak masuk dari Maildir / Database
        $this->loadEmailsFromDatabase();

        if ($this->currentAccount) {
            $actualBytes = $this->calculateActualEmailsBytes();
            $this->currentAccount->syncMaildirDiskUsage($actualBytes);
            $this->currentAccount->refresh();
        }

        session()->flash('webmail_msg', 'Kotak masuk diperbarui dari Maildir Dovecot.');
    }

    protected function loadEmailsFromDatabase()
    {
        if (!$this->currentAccount) return;

        $dbEmails = MailboxEmail::where('virtual_user_id', $this->currentAccount->id)->get();
        if ($dbEmails->isEmpty()) {
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

    public function toggleSelectAll()
    {
        $currentIds = collect($this->emails)->where('folder', $this->activeFolder)->pluck('id')->toArray();
        if (count($this->selectedIds) >= count($currentIds) && count($currentIds) > 0) {
            $this->selectedIds = [];
        } else {
            $this->selectedIds = $currentIds;
        }
    }

    public function deleteSelectedMultiple()
    {
        if (empty($this->selectedIds)) return;
        foreach ($this->emails as $index => $item) {
            if (in_array($item['id'], $this->selectedIds)) {
                $this->emails[$index]['folder'] = 'trash';
            }
        }
        $this->selectedIds = [];
        $this->persistState();
        session()->flash('webmail_msg', 'Pesan terpilih dipindahkan ke Sampah.');
    }

    public function markMultipleRead()
    {
        if (empty($this->selectedIds)) return;
        foreach ($this->emails as $index => $item) {
            if (in_array($item['id'], $this->selectedIds)) {
                $this->emails[$index]['is_read'] = true;
            }
        }
        $this->selectedIds = [];
        $this->persistState();
    }

    public function selectEmail($id)
    {
        $this->selectedEmailId = $id;
        $this->viewMode = 'detail';
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
            ->when($this->activeFilter === 'starred', function ($collection) {
                return $collection->where('is_starred', true);
            })
            ->when($this->activeFilter === 'unread', function ($collection) {
                return $collection->where('is_read', false);
            })
            ->when($this->activeFilter === 'read', function ($collection) {
                return $collection->where('is_read', true);
            })
            ->values();

        $selectedEmail = $this->selectedEmailId ? collect($this->emails)->first(function ($item) {
            return (string)($item['id'] ?? '') === (string)$this->selectedEmailId;
        }) : null;

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

<div class="h-full min-h-0 flex-1 flex flex-col rounded-2xl bg-slate-900/90 border border-slate-800/90 overflow-hidden shadow-2xl backdrop-blur-xl"
     x-data="{ showFolderSidebar: false, mobileEmailOpen: false }">
    
    <!-- Top Action Toolbar -->
    <div class="min-h-14 px-4 py-2.5 border-b border-slate-800/80 bg-slate-950/60 backdrop-blur-md flex flex-wrap items-center justify-between gap-3 shrink-0">
        <div class="flex items-center gap-2 sm:gap-3">
            <!-- Mobile Toggle Folder Button -->
            <button @click="showFolderSidebar = !showFolderSidebar" class="md:hidden p-2 rounded-xl bg-slate-800/80 text-slate-300 hover:text-white border border-slate-700/60" title="Pilih Folder">
                <i data-lucide="folder" class="w-4 h-4"></i>
            </button>

            <!-- Tulis Pesan Button with Gradient Glow -->
            <button wire:click="$set('showComposeModal', true)" 
                    class="px-4 py-2 bg-gradient-to-r from-cyan-500 to-indigo-600 hover:from-cyan-400 hover:to-indigo-500 text-white text-xs font-bold rounded-xl flex items-center gap-2 transition-all shadow-lg shadow-cyan-500/20 hover:shadow-cyan-500/30 hover:scale-[1.02] active:scale-[0.98]">
                <i data-lucide="edit-3" class="w-3.5 h-3.5"></i>
                <span>Tulis Pesan</span>
            </button>

            <div class="hidden sm:block h-5 w-px bg-slate-800"></div>

            <!-- Active Folder Indicator Badge -->
            <div class="flex items-center gap-1.5 px-3 py-1 rounded-xl bg-slate-900/80 border border-slate-800 text-xs text-slate-300 font-medium">
                <i data-lucide="{{ $activeFolder === 'inbox' ? 'inbox' : ($activeFolder === 'sent' ? 'send' : ($activeFolder === 'drafts' ? 'file-text' : ($activeFolder === 'spam' ? 'alert-octagon' : ($activeFolder === 'trash' ? 'trash-2' : 'folder')))) }}" class="w-3.5 h-3.5 text-cyan-400"></i>
                <span class="text-slate-400">Folder:</span>
                <span class="font-bold text-white capitalize">
                    {{ $activeFolder === 'inbox' ? 'Kotak Masuk' : ($activeFolder === 'sent' ? 'Terkirim' : ($activeFolder === 'drafts' ? 'Drafts' : ($activeFolder === 'spam' ? 'Spam' : ($activeFolder === 'trash' ? 'Sampah' : $activeFolder)))) }}
                </span>
            </div>
        </div>

        @if (session()->has('webmail_msg'))
            <div class="px-3.5 py-1.5 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs flex items-center gap-2 shadow-sm animate-pulse">
                <i data-lucide="check-circle-2" class="w-4 h-4 text-emerald-400"></i>
                <span>{{ session('webmail_msg') }}</span>
            </div>
        @endif

        <div class="flex items-center gap-3">
            <div class="flex items-center gap-2 px-3 py-1 rounded-full bg-slate-900/90 border border-slate-800 text-[11px] text-slate-400 shadow-inner">
                <span class="relative flex h-2 w-2">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                </span>
                <span class="font-medium text-slate-300">Dovecot IMAP Active</span>
            </div>
        </div>
    </div>

    <!-- 3-Column Webmail Layout -->
    <div class="flex-1 flex overflow-hidden relative">
        <!-- Kolom 1: Folder Navigasi (Collapsible Drawer on Mobile, w-60 on Desktop) -->
        <div :class="showFolderSidebar ? 'translate-x-0' : '-translate-x-full md:translate-x-0'"
             class="absolute md:static inset-y-0 left-0 w-60 bg-slate-950/95 md:bg-slate-950/70 backdrop-blur-xl border-r border-slate-800/80 p-3 flex flex-col justify-between shrink-0 z-20 transition-transform duration-200 ease-in-out h-full overflow-hidden">
            <!-- Scrollable Folder List Area -->
            <div class="flex-1 min-h-0 overflow-y-auto space-y-1.5 pr-1 pb-2">
                <div class="flex items-center justify-between md:hidden pb-2 mb-2 border-b border-slate-800">
                    <span class="text-xs font-bold text-slate-300">Navigasi Folder</span>
                    <button @click="showFolderSidebar = false" class="text-slate-400 hover:text-white p-1 rounded-lg hover:bg-slate-800">
                        <i data-lucide="x" class="w-4 h-4"></i>
                    </button>
                </div>

                <!-- Inbox dengan Tombol Refresh (Gaya Hostinger) -->
                <div class="w-full flex items-center justify-between px-2.5 py-1.5 rounded-xl text-xs font-medium transition-all group {{ $activeFolder === 'inbox' ? 'bg-gradient-to-r from-cyan-600 to-indigo-600 text-white font-bold shadow-lg shadow-indigo-600/20' : 'text-slate-400 hover:bg-slate-800/70 hover:text-slate-200' }}">
                    <button wire:click="selectFolder('inbox')" @click="showFolderSidebar = false" class="flex-1 flex items-center gap-2.5 truncate text-left">
                        <div class="p-1 rounded-lg {{ $activeFolder === 'inbox' ? 'bg-white/20' : 'bg-slate-800/50 group-hover:bg-slate-800 text-cyan-400' }}">
                            <i data-lucide="inbox" class="w-3.5 h-3.5"></i>
                        </div>
                        <span class="truncate">Kotak Masuk</span>
                    </button>
                    
                    <div class="flex items-center gap-1.5 shrink-0">
                        @if($counts['inbox'] > 0)
                            <span class="text-[10px] px-2 py-0.5 rounded-full {{ $activeFolder === 'inbox' ? 'bg-white/20 text-white' : 'bg-cyan-500/15 text-cyan-300 border border-cyan-500/30' }} font-bold">
                                {{ $counts['inbox'] }}
                            </span>
                        @endif
                        
                        <!-- Tombol Refresh Kotak Masuk -->
                        <button type="button" 
                                wire:click.stop="refreshInbox" 
                                class="p-1 rounded-lg {{ $activeFolder === 'inbox' ? 'text-white/80 hover:text-white hover:bg-white/20' : 'text-slate-400 hover:text-cyan-300 hover:bg-slate-800' }} transition-all"
                                title="Segarkan Kotak Masuk">
                            <i data-lucide="rotate-cw" class="w-3.5 h-3.5" wire:loading.class="animate-spin" wire:target="refreshInbox"></i>
                        </button>
                    </div>
                </div>

                <!-- Sent -->
                <button wire:click="selectFolder('sent')" @click="showFolderSidebar = false"
                        class="w-full flex items-center justify-between px-3 py-2 rounded-xl text-xs font-medium transition-all group {{ $activeFolder === 'sent' ? 'bg-gradient-to-r from-cyan-600 to-indigo-600 text-white font-bold shadow-lg shadow-indigo-600/20' : 'text-slate-400 hover:bg-slate-800/70 hover:text-slate-200' }}">
                    <div class="flex items-center gap-2.5">
                        <div class="p-1 rounded-lg {{ $activeFolder === 'sent' ? 'bg-white/20' : 'bg-slate-800/50 group-hover:bg-slate-800 text-indigo-400' }}">
                            <i data-lucide="send" class="w-3.5 h-3.5"></i>
                        </div>
                        <span>Terkirim</span>
                    </div>
                </button>

                <!-- Drafts -->
                <button wire:click="selectFolder('drafts')" @click="showFolderSidebar = false"
                        class="w-full flex items-center justify-between px-3 py-2 rounded-xl text-xs font-medium transition-all group {{ $activeFolder === 'drafts' ? 'bg-gradient-to-r from-cyan-600 to-indigo-600 text-white font-bold shadow-lg shadow-indigo-600/20' : 'text-slate-400 hover:bg-slate-800/70 hover:text-slate-200' }}">
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
                <button wire:click="selectFolder('spam')" @click="showFolderSidebar = false"
                        class="w-full flex items-center justify-between px-3 py-2 rounded-xl text-xs font-medium transition-all group {{ $activeFolder === 'spam' ? 'bg-gradient-to-r from-cyan-600 to-indigo-600 text-white font-bold shadow-lg shadow-indigo-600/20' : 'text-slate-400 hover:bg-slate-800/70 hover:text-slate-200' }}">
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
                <button wire:click="selectFolder('trash')" @click="showFolderSidebar = false"
                        class="w-full flex items-center justify-between px-3 py-2 rounded-xl text-xs font-medium transition-all group {{ $activeFolder === 'trash' ? 'bg-gradient-to-r from-cyan-600 to-indigo-600 text-white font-bold shadow-lg shadow-indigo-600/20' : 'text-slate-400 hover:bg-slate-800/70 hover:text-slate-200' }}">
                    <div class="flex items-center gap-2.5">
                        <div class="p-1 rounded-lg {{ $activeFolder === 'trash' ? 'bg-white/20' : 'bg-slate-800/50 group-hover:bg-slate-800 text-slate-400' }}">
                            <i data-lucide="trash" class="w-3.5 h-3.5"></i>
                        </div>
                        <span>Sampah</span>
                    </div>
                </button>

                <!-- Kumpulan Folder Kustom -->
                <div class="pt-3 mt-2 border-t border-slate-800/80">
                    <div class="flex items-center justify-between px-2 mb-1.5">
                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Folder Kustom</span>
                        <button @click="$wire.set('showCreateFolderModal', true)" 
                                class="p-1 rounded-lg hover:bg-indigo-500/20 text-indigo-400 hover:text-indigo-300 transition-colors" 
                                title="Buat Folder Baru">
                            <i data-lucide="folder-plus" class="w-3.5 h-3.5"></i>
                        </button>
                    </div>

                    <div class="space-y-1">
                        @foreach($customFolders as $folder)
                        <div class="group relative flex items-center justify-between rounded-xl transition-all {{ $activeFolder === $folder ? 'bg-gradient-to-r from-cyan-600 to-indigo-600 text-white font-bold shadow-md shadow-indigo-600/20' : 'text-slate-400 hover:bg-slate-800/70 hover:text-white' }}">
                            <button wire:click="selectFolder('{{ $folder }}')" @click="showFolderSidebar = false"
                                    class="w-full flex items-center gap-2 px-2.5 py-1.5 text-xs font-medium truncate text-left">
                                <i data-lucide="folder" class="w-3.5 h-3.5 {{ $activeFolder === $folder ? 'text-white' : 'text-cyan-400' }} shrink-0"></i>
                                <span class="truncate">{{ $folder }}</span>
                            </button>
                            
                            <!-- Tombol Hapus Folder -->
                            <button type="button" 
                                    wire:click="deleteFolder('{{ $folder }}')" 
                                    wire:confirm="Yakin ingin menghapus folder '{{ $folder }}'? Email di dalamnya akan dipindahkan ke Kotak Masuk."
                                    class="opacity-0 group-hover:opacity-100 p-1 mr-1 rounded-lg hover:bg-rose-500/20 text-slate-400 hover:text-rose-400 transition-all shrink-0" 
                                    title="Hapus folder {{ $folder }}">
                                <i data-lucide="trash-2" class="w-3 h-3"></i>
                            </button>
                        </div>
                        @endforeach
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

        <!-- Kolom Konten Utama: Mode Daftar (Gmail/Hostinger List) vs Mode Detail Pesan -->
        <div class="flex-1 flex flex-col min-w-0 h-full overflow-hidden bg-slate-950">

            @if($viewMode === 'list')
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
                            <button type="button" 
                                    wire:click="refreshInbox" 
                                    class="p-1.5 rounded-xl bg-slate-800/60 hover:bg-slate-800 text-slate-400 hover:text-cyan-300 border border-slate-700/60 transition-colors"
                                    title="Segarkan Pesan (Refresh Inbox)">
                                <i data-lucide="rotate-cw" class="w-3.5 h-3.5" wire:loading.class="animate-spin" wire:target="refreshInbox"></i>
                            </button>
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
                    <div wire:click="selectEmail({{ $email['id'] }})"
                         class="group px-4 sm:px-6 py-3 cursor-pointer transition-colors flex items-center gap-3.5 hover:bg-slate-900/60 {{ $email['is_read'] ? 'bg-slate-950/20 text-slate-300' : 'bg-slate-900/30 text-white font-semibold' }}">
                        
                        <!-- Checkbox & Star (prevent parent click with @click.stop) -->
                        <div class="flex items-center gap-2.5 shrink-0" @click.stop>
                            <input type="checkbox" wire:model.live="selectedIds" value="{{ $email['id'] }}"
                                   class="w-4 h-4 rounded border-slate-700 text-cyan-500 focus:ring-0 focus:ring-offset-0 bg-slate-900 cursor-pointer">
                            <button wire:click="toggleStar({{ $email['id'] }})" class="p-1 text-slate-500 hover:text-amber-400 transition-colors">
                                <i data-lucide="star" class="w-4 h-4 {{ $email['is_starred'] ? 'fill-amber-400 text-amber-400' : '' }}"></i>
                            </button>
                        </div>

                        <!-- Sender Name -->
                        <div class="w-44 sm:w-56 shrink-0 truncate text-xs sm:text-sm flex items-center gap-2">
                            @if(!$email['is_read'])
                                <span class="w-2 h-2 rounded-full bg-cyan-400 shrink-0 ring-4 ring-cyan-400/20" title="Belum Dibaca"></span>
                            @else
                                <span class="w-2 h-2 rounded-full bg-transparent shrink-0"></span>
                            @endif
                            <span class="truncate {{ $email['is_read'] ? 'font-medium text-slate-300' : 'font-bold text-white' }}">
                                {{ $email['from_name'] }}
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
                            <span class="text-slate-500 font-normal truncate hidden md:inline">
                                - {{ Str::limit(strip_tags($email['body']), 90) }}
                            </span>
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
                                <button wire:click="moveToFolder('trash')" class="p-1.5 rounded-lg hover:bg-rose-500/20 text-slate-400 hover:text-rose-400" title="Hapus">
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
                 x-data="{ showInlineReply: false }"
                 wire:key="email-detail-view-{{ $selectedEmailId }}">
                @if($selectedEmail)
                <!-- Hostinger Action Bar (← Back, Mail, Spam, Trash, Move, Summarize AI) -->
                <div class="px-6 py-3 border-b border-slate-800/80 bg-slate-900/60 flex items-center justify-between gap-3 shrink-0">
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
                                    title="Hapus Pesan">
                                <i data-lucide="trash-2" class="w-4 h-4"></i>
                            </button>
                            @else
                            <button wire:click="restoreFromTrash" 
                                    class="px-2.5 py-1 rounded-xl bg-emerald-500/10 text-emerald-400 border border-emerald-500/30 text-xs font-semibold flex items-center gap-1.5" 
                                    title="Kembalikan ke Kotak Masuk">
                                <i data-lucide="archive-restore" class="w-4 h-4"></i>
                                <span>Kembalikan</span>
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
                <div class="flex-1 overflow-y-auto px-6 sm:px-10 py-6 space-y-6">
                    
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
                                <button @click="showHeaderDetails = !showHeaderDetails" class="hover:text-slate-200 flex items-center gap-1 font-medium transition-colors">
                                    <span>to me</span>
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

                    <!-- Hostinger Attachments Pills / Badges (Merah PDF Icon, Filename, Size, Unduh Icon) -->
                    @if(!empty($selectedEmail['attachments']))
                    <div class="space-y-2 pt-2 pb-4 border-b border-slate-800/80">
                        <div class="flex flex-wrap items-center gap-3">
                            @foreach($selectedEmail['attachments'] as $att)
                            @php
                                $isObject = is_array($att);
                                $name = $isObject ? ($att['name'] ?? 'Dokumen.pdf') : $att;
                                $size = $isObject ? ($att['size'] ?? '1 MB') : '1.2 MB';
                            @endphp
                            <div class="flex items-center gap-3 px-3.5 py-2.5 rounded-2xl bg-slate-900 border border-slate-700/80 hover:border-slate-600 shadow-sm transition-all group">
                                <!-- PDF Red Badge Icon -->
                                <div class="w-8 h-8 rounded-xl bg-rose-600 text-white flex items-center justify-center font-bold text-[10px] tracking-tighter shrink-0 shadow-sm shadow-rose-600/30">
                                    PDF
                                </div>
                                <div class="min-w-0 flex items-center gap-2">
                                    <span class="text-xs font-semibold text-slate-200 truncate max-w-[180px] sm:max-w-[240px]" title="{{ $name }}">
                                        {{ $name }}
                                    </span>
                                    <span class="text-xs text-slate-400 shrink-0 font-medium">
                                        {{ $size }}
                                    </span>
                                </div>
                                <!-- Download Button -->
                                <button type="button" 
                                        onclick="alert('Mengunduh dokumen lampiran: {{ $name }}')"
                                        class="p-1.5 rounded-lg text-slate-400 group-hover:text-white hover:bg-slate-800 transition-colors ml-1" 
                                        title="Unduh {{ $name }}">
                                    <i data-lucide="download" class="w-4 h-4"></i>
                                </button>
                            </div>
                            @endforeach
                        </div>

                        <!-- Attachment Count & Download All Link -->
                        <div class="flex items-center gap-3 text-xs text-slate-400 pt-1">
                            <span>{{ count($selectedEmail['attachments']) }} attachments</span>
                            <span>•</span>
                            <button type="button" 
                                    onclick="alert('Mengunduh semua dokumen lampiran zip...')"
                                    class="text-indigo-400 hover:text-indigo-300 font-semibold hover:underline">
                                Download all
                            </button>
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

                    <!-- Email Body Text -->
                    <div class="text-sm sm:text-base text-slate-200 leading-relaxed font-sans whitespace-pre-line py-2">
                        {{ $selectedEmail['body'] }}
                    </div>

                    <!-- Hostinger Bottom Action Buttons (Reply & Forward Rounded Pills) -->
                    <div class="pt-6 border-t border-slate-800/80 flex items-center gap-3">
                        <button type="button" 
                                wire:click="replyEmail"
                                class="px-5 py-2 rounded-full border border-slate-700 bg-slate-900 hover:bg-slate-800 hover:border-slate-600 text-slate-200 hover:text-white text-xs font-semibold flex items-center gap-2 transition-all shadow-sm">
                            <i data-lucide="reply" class="w-3.5 h-3.5 text-slate-300"></i>
                            <span>Reply</span>
                        </button>

                        <button type="button" 
                                wire:click="forwardEmail"
                                class="px-5 py-2 rounded-full border border-slate-700 bg-slate-900 hover:bg-slate-800 hover:border-slate-600 text-slate-200 hover:text-white text-xs font-semibold flex items-center gap-2 transition-all shadow-sm">
                            <i data-lucide="forward" class="w-3.5 h-3.5 text-slate-300"></i>
                            <span>Forward</span>
                        </button>
                    </div>

                    <!-- Quick Reply Form (Jika Dibuka) -->
                    <div x-show="showInlineReply" 
                         x-transition:enter="transition ease-out duration-200"
                         x-transition:enter-start="opacity-0 -translate-y-2"
                         x-transition:enter-end="opacity-100 translate-y-0"
                         class="p-5 rounded-3xl bg-slate-900/95 border border-indigo-500/40 shadow-2xl space-y-4 mt-4">
                        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                            <div class="flex items-center gap-2 text-xs">
                                <span class="font-bold text-white">Membalas ke:</span>
                                <span class="text-cyan-300 font-mono">{{ $selectedEmail['from_email'] }}</span>
                            </div>
                            <button @click="showInlineReply = false" class="text-slate-400 hover:text-white p-1 rounded-lg">
                                <i data-lucide="x" class="w-4 h-4"></i>
                            </button>
                        </div>

                        <form wire:submit="sendQuickReply" class="space-y-3">
                            <textarea wire:model="quickReplyText" rows="4" placeholder="Ketik balasan Anda..." 
                                      class="w-full px-4 py-3 bg-slate-950 border border-slate-700 rounded-2xl text-xs sm:text-sm text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500 font-sans"></textarea>
                            @error('quickReplyText') <span class="text-xs text-rose-400 block">{{ $message }}</span> @enderror

                            <div class="flex items-center justify-between pt-1">
                                <label class="cursor-pointer text-cyan-400 hover:text-cyan-300 flex items-center gap-2 text-xs font-semibold py-1.5 px-3 rounded-xl bg-slate-950 border border-slate-800">
                                    <i data-lucide="paperclip" class="w-3.5 h-3.5"></i>
                                    <span>Lampiran</span>
                                    <input type="file" wire:model="quickReplyAttachments" multiple class="hidden">
                                </label>
                                <div class="flex items-center gap-2">
                                    <button type="button" @click="showInlineReply = false" class="px-3 py-1.5 text-xs text-slate-400 hover:text-white">Batal</button>
                                    <button type="submit" class="px-4 py-2 bg-gradient-to-r from-cyan-600 to-indigo-600 hover:from-cyan-500 hover:to-indigo-500 text-white font-bold text-xs rounded-xl shadow-md">
                                        Kirim Balasan
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
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
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/75 backdrop-blur-md p-4">
        <div class="w-full max-w-2xl bg-slate-900/95 border border-slate-700/80 rounded-3xl shadow-2xl overflow-hidden flex flex-col backdrop-blur-xl">
            <div class="px-6 py-4 border-b border-slate-800 flex items-center justify-between bg-slate-950/70">
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

            <form wire:submit="sendEmail" class="p-6 space-y-4">
                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label class="block text-xs font-bold text-slate-300">Kepada (To)</label>
                        <button type="button" wire:click="$toggle('showCcBcc')" class="text-[11px] text-cyan-400 hover:text-cyan-300 font-semibold">
                            {{ $showCcBcc ? 'Sembunyikan CC/BCC' : '+ Tambah CC / BCC' }}
                        </button>
                    </div>
                    <input type="email" wire:model="composeTo" placeholder="alamat@tujuan.com" 
                           class="w-full px-4 py-2.5 bg-slate-950/90 border border-slate-700/80 rounded-xl text-xs text-white placeholder-slate-500 focus:outline-none focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500 shadow-inner">
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
                    <textarea rows="7" wire:model="composeBody" placeholder="Tulis isi pesan email Anda di sini..." 
                              class="w-full px-4 py-3 bg-slate-950/90 border border-slate-700/80 rounded-2xl text-xs sm:text-sm text-white placeholder-slate-500 focus:outline-none focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500 font-sans shadow-inner"></textarea>
                </div>

                <!-- File Attachment Field -->
                <div>
                    <div class="flex items-center justify-between">
                        <label class="cursor-pointer inline-flex items-center gap-2 text-xs text-cyan-400 hover:text-cyan-300 font-semibold py-1.5 px-3 rounded-xl bg-slate-950 border border-slate-800 hover:border-cyan-500/50 transition-all">
                            <i data-lucide="paperclip" class="w-4 h-4"></i>
                            <span>Lampirkan File Dokumen</span>
                            <input type="file" wire:model="attachments" multiple class="hidden">
                        </label>
                        <span wire:loading wire:target="attachments" class="text-[11px] text-amber-400 animate-pulse">Mengunggah file...</span>
                    </div>

                    @if(!empty($attachments))
                    <div class="flex flex-wrap gap-2 mt-2.5">
                        @foreach($attachments as $file)
                        <span class="px-3 py-1.5 rounded-xl bg-slate-950 border border-slate-700 text-xs text-cyan-300 font-mono flex items-center gap-2 shadow-sm">
                            <i data-lucide="file-check" class="w-3.5 h-3.5 text-emerald-400"></i>
                            {{ $file->getClientOriginalName() }}
                        </span>
                        @endforeach
                    </div>
                    @endif
                </div>

                <div class="flex items-center justify-between gap-3 pt-4 border-t border-slate-800">
                    <div class="flex items-center gap-2">
                        <button type="button" wire:click="discardDraft" 
                                class="p-2.5 rounded-xl text-slate-400 hover:text-rose-400 hover:bg-rose-500/10 transition-colors" 
                                title="Buang draf ini">
                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                        </button>
                        <button type="button" wire:click="saveDraftNow" 
                                class="px-4 py-2 rounded-xl bg-slate-800/90 hover:bg-slate-750 text-slate-300 text-xs font-semibold flex items-center gap-2 transition-all border border-slate-700/60">
                            <i data-lucide="save" class="w-3.5 h-3.5"></i>
                            <span>Simpan Draf</span>
                        </button>
                    </div>

                    <div class="flex items-center gap-2.5">
                        <button type="button" wire:click="closeComposeModal" class="px-4 py-2 text-xs font-medium text-slate-400 hover:text-white">
                            Tutup
                        </button>
                        <button type="submit" class="px-6 py-2.5 bg-gradient-to-r from-cyan-600 to-indigo-600 hover:from-cyan-500 hover:to-indigo-500 text-white font-bold text-xs rounded-xl transition-all shadow-lg shadow-cyan-600/25 flex items-center gap-2 hover:scale-[1.02] active:scale-[0.98]">
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
</div>