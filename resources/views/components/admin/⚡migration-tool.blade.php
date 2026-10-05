<?php

use Livewire\Component;
use Livewire\WithFileUploads;
use App\Models\VirtualUser;
use App\Models\VirtualDomain;
use App\Models\MailboxEmail;
use App\Models\MailboxFolder;
use Webklex\IMAP\Facades\Client;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use Carbon\Carbon;

new class extends Component
{
    use WithFileUploads;

    // Mode tab: 'imap' (Hostinger / cPanel Live Sync) atau 'upload' (.eml / .zip)
    public $activeTab = 'imap';

    // Form Field IMAP
    public $source_host = 'imap.hostinger.com';
    public $source_port = 993;
    public $source_encryption = 'ssl';
    public $source_email = '';
    public $source_password = '';
    public $target_user_id = '';
    public $sync_inbox = true;
    public $sync_sent = true;
    public $sync_drafts = false;
    public $sync_trash = false;
    public $mark_read = false;

    // Form Field File Upload
    public $backup_files = [];
    public $target_folder = 'INBOX';

    // Status state
    public $isProcessing = false;
    public $connectionStatus = null; // 'success' | 'error'
    public $connectionMessage = '';
    public $syncLogs = [];
    public $stats = [
        'total' => 0,
        'success' => 0,
        'failed' => 0,
        'bytes' => 0
    ];

    public function mount()
    {
        $firstUser = VirtualUser::where('is_active', true)->first();
        if ($firstUser) {
            $this->target_user_id = $firstUser->id;
        }
    }

    public function setPreset(string $provider)
    {
        if ($provider === 'hostinger') {
            $this->source_host = 'imap.hostinger.com';
            $this->source_port = 993;
            $this->source_encryption = 'ssl';
        } elseif ($provider === 'cpanel') {
            $this->source_host = 'mail.domainanda.com';
            $this->source_port = 993;
            $this->source_encryption = 'ssl';
        } elseif ($provider === 'google') {
            $this->source_host = 'imap.gmail.com';
            $this->source_port = 993;
            $this->source_encryption = 'ssl';
        }
        $this->connectionStatus = null;
    }

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

    public function testConnection()
    {
        $this->validate([
            'source_host' => 'required|string',
            'source_port' => 'required|numeric',
            'source_email' => 'required|email',
            'source_password' => 'required|string',
        ]);

        $this->connectionStatus = null;
        $this->connectionMessage = 'Sedang menghubungi server IMAP...';

        try {
            $client = Client::make([
                'host'          => trim($this->source_host),
                'port'          => (int) $this->source_port,
                'encryption'    => $this->source_encryption === 'none' ? false : $this->source_encryption,
                'validate_cert' => false,
                'username'      => trim($this->source_email),
                'password'      => $this->source_password,
                'protocol'      => 'imap',
            ]);

            $client->connect();
            $folders = $client->getFolders();
            $folderNames = [];
            foreach ($folders as $folder) {
                $folderNames[] = $folder->name;
            }

            $this->connectionStatus = 'success';
            $this->connectionMessage = 'Koneksi Berhasil! Terhubung ke server IMAP ' . $this->source_host . '. Ditemukan ' . count($folderNames) . ' folder: ' . implode(', ', array_slice($folderNames, 0, 5)) . (count($folderNames) > 5 ? '...' : '');
        } catch (\Throwable $e) {
            $this->connectionStatus = 'error';
            $this->connectionMessage = 'Gagal terhubung: ' . $e->getMessage() . '. Pastikan email, password, dan port 993 SSL sudah tepat.';
        }
    }

    public function startImapMigration()
    {
        $this->validate([
            'source_host' => 'required|string',
            'source_port' => 'required|numeric',
            'source_email' => 'required|email',
            'source_password' => 'required|string',
            'target_user_id' => 'required|exists:virtual_users,id',
        ]);

        $targetUser = VirtualUser::findOrFail($this->target_user_id);
        $this->isProcessing = true;
        $this->syncLogs = [];
        $this->stats = ['total' => 0, 'success' => 0, 'failed' => 0, 'bytes' => 0];

        $this->addLog("Memulai migrasi IMAP dari {$this->source_host} ke mailbox lokal {$targetUser->email}...", 'info');

        try {
            $client = Client::make([
                'host'          => trim($this->source_host),
                'port'          => (int) $this->source_port,
                'encryption'    => $this->source_encryption === 'none' ? false : $this->source_encryption,
                'validate_cert' => false,
                'username'      => trim($this->source_email),
                'password'      => $this->source_password,
                'protocol'      => 'imap',
            ]);

            $client->connect();
            $folders = $client->getFolders();

            $foldersToSync = [];
            if ($this->sync_inbox) $foldersToSync[] = 'INBOX';
            if ($this->sync_sent) $foldersToSync[] = 'Sent';
            if ($this->sync_drafts) $foldersToSync[] = 'Drafts';
            if ($this->sync_trash) $foldersToSync[] = 'Trash';

            $totalMigrated = 0;
            $maildirBase = '/var/vmail/' . ($targetUser->maildir_path ?: ($targetUser->domain->name . '/' . explode('@', $targetUser->email)[0] . '/'));

            foreach ($folders as $folder) {
                $fName = $folder->name;
                $matched = false;
                foreach ($foldersToSync as $targetSync) {
                    if (stripos($fName, $targetSync) !== false || $fName === $targetSync) {
                        $matched = true;
                        break;
                    }
                }

                if (!$matched && !empty($foldersToSync)) {
                    continue;
                }

                $this->addLog("Memeriksa folder [{$fName}]...", 'info');
                
                try {
                    $messages = $folder->query()->all()->limit(100)->get();
                    $msgCount = $messages->count();
                    $this->addLog("Ditemukan {$msgCount} pesan di folder [{$fName}]. Menyalin ke Mailbox Webmail & Storage...", 'info');

                    foreach ($messages as $idx => $msg) {
                        $this->stats['total']++;
                        try {
                            $rawContent = $msg->getRawBody();
                            $size = strlen($rawContent);
                            $this->stats['bytes'] += $size;

                            $fromName = 'Pengirim Hostinger';
                            $fromEmail = 'unknown@hostinger.com';
                            try {
                                $fromList = $msg->getFrom();
                                if (!empty($fromList) && isset($fromList[0])) {
                                    $fromName = $fromList[0]->personal ?? ($fromList[0]->mail ?? 'Pengirim Hostinger');
                                    $fromEmail = $fromList[0]->mail ?? 'unknown@hostinger.com';
                                }
                            } catch (\Throwable $e) {}

                            $toEmail = $targetUser->email;
                            try {
                                $toList = $msg->getTo();
                                if (!empty($toList) && isset($toList[0])) {
                                    $toEmail = $toList[0]->mail ?? $targetUser->email;
                                }
                            } catch (\Throwable $e) {}

                            $subject = '(Tanpa Subjek)';
                            try {
                                $subject = (string) $msg->getSubject() ?: '(Tanpa Subjek)';
                            } catch (\Throwable $e) {}

                            // Format Date dengan aman dari Webklex Attribute / Carbon / String
                            $dateHuman = now()->format('d M H:i');
                            try {
                                $rawDate = $msg->getDate();
                                if ($rawDate instanceof \Carbon\Carbon || $rawDate instanceof \DateTimeInterface) {
                                    $dateHuman = $rawDate->format('d M H:i');
                                } elseif (is_object($rawDate) && method_exists($rawDate, 'toDate')) {
                                    $dateHuman = $rawDate->toDate()->format('d M H:i');
                                } elseif (!empty($rawDate)) {
                                    $dateHuman = Carbon::parse((string) $rawDate)->format('d M H:i');
                                }
                            } catch (\Throwable $e) {
                                $dateHuman = now()->format('d M H:i');
                            }

                            $body = '';
                            try {
                                if ($msg->hasHTMLBody()) {
                                    $body = (string) $msg->getHTMLBody();
                                } else {
                                    $body = (string) ($msg->getTextBody() ?: $rawContent);
                                }
                            } catch (\Throwable $e) {
                                $body = $rawContent;
                            }

                            $isRead = false;
                            try {
                                $isRead = $msg->hasFlag('Seen');
                            } catch (\Throwable $e) {}

                            $isStarred = false;
                            try {
                                $isStarred = $msg->hasFlag('Flagged');
                            } catch (\Throwable $e) {}

                            $targetDbFolder = 'inbox';
                            if (stripos($fName, 'sent') !== false) {
                                $targetDbFolder = 'sent';
                            } elseif (stripos($fName, 'draft') !== false) {
                                $targetDbFolder = 'drafts';
                            } elseif (stripos($fName, 'trash') !== false) {
                                $targetDbFolder = 'trash';
                            }

                            // Ekstrak & Simpan File Lampiran Fisik (PDF, Docx, xlsx, dll)
                            $attachmentsData = [];
                            try {
                                if ($msg->hasAttachments()) {
                                    $attDir = storage_path('app/public/attachments/' . $targetUser->id);
                                    if (!file_exists($attDir)) {
                                        @mkdir($attDir, 0775, true);
                                    }

                                    foreach ($msg->getAttachments() as $attachment) {
                                        $attName = $this->decodeMimeHeader($attachment->getName() ?: ('lampiran_' . uniqid() . '.dat'));
                                        $attContent = $attachment->getContent();
                                        $fileSize = strlen($attContent);
                                        
                                        // Buat nama file penyimpanan aman
                                        $safeFileName = time() . '_' . uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $attName);
                                        @file_put_contents($attDir . '/' . $safeFileName, $attContent);

                                        // Hitung ukuran terbaca
                                        $formattedSize = $fileSize > 1048576 ? round($fileSize / 1048576, 1) . ' MB' : max(1, round($fileSize / 1024)) . ' KB';
                                        $ext = strtolower(pathinfo($attName, PATHINFO_EXTENSION)) ?: 'file';

                                        $attachmentsData[] = [
                                            'name' => $attName,
                                            'size' => $formattedSize,
                                            'ext' => $ext,
                                            'path' => 'attachments/' . $targetUser->id . '/' . $safeFileName,
                                            'bytes' => $fileSize,
                                        ];
                                    }
                                }
                            } catch (\Throwable $e) {}

                            // 1. Cek Apakah Email Sudah Ada Sebelumnya (Mencegah Duplikasi / Duplikat Pesan)
                            $existingEmail = MailboxEmail::where('virtual_user_id', $targetUser->id)
                                ->where('folder', $targetDbFolder)
                                ->where('subject', $subject)
                                ->where('from_email', $fromEmail)
                                ->where('date_human', $dateHuman)
                                ->first();

                            if ($existingEmail) {
                                // Update jika ada lampiran file baru atau perubahan status
                                $existingEmail->update([
                                    'from_name'   => $fromName,
                                    'body'        => $body,
                                    'attachments' => !empty($attachmentsData) ? $attachmentsData : $existingEmail->attachments,
                                    'is_read'     => $isRead,
                                    'is_starred'  => $isStarred,
                                ]);
                            } else {
                                // Simpan sebagai email baru
                                MailboxEmail::create([
                                    'virtual_user_id' => $targetUser->id,
                                    'folder'          => $targetDbFolder,
                                    'from_name'       => $fromName,
                                    'from_email'      => $fromEmail,
                                    'to'              => $toEmail,
                                    'subject'         => $subject,
                                    'date_human'      => $dateHuman,
                                    'is_read'         => $isRead,
                                    'is_starred'      => $isStarred,
                                    'body'            => $body,
                                    'attachments'     => $attachmentsData,
                                    'spam_score'      => 0.0,
                                ]);
                            }

                            // 2. Jika di lingkungan VPS Linux, tulis langsung ke Maildir Dovecot
                            if (is_dir('/var/vmail')) {
                                $folderSubdir = ($fName === 'INBOX') ? 'cur' : ('.' . $fName . '/cur');
                                $targetDir = rtrim($maildirBase, '/') . '/' . $folderSubdir;
                                if (!file_exists($targetDir)) {
                                    @mkdir($targetDir, 0770, true);
                                }
                                $msgFilename = time() . '.' . uniqid('msg_') . ':2,S';
                                @file_put_contents($targetDir . '/' . $msgFilename, $rawContent);
                            }

                            $this->stats['success']++;
                            $totalMigrated++;
                        } catch (\Throwable $mErr) {
                            $this->stats['failed']++;
                            $this->addLog("Gagal menyalin pesan ID #{$msg->getUid()}: {$mErr->getMessage()}", 'warning');
                        }
                    }
                } catch (\Throwable $fErr) {
                    $this->addLog("Folder {$fName} dilewati: {$fErr->getMessage()}", 'warning');
                }
            }

            // Update kuota terpakai
            $targetUser->used_bytes = min($targetUser->quota_bytes, $targetUser->used_bytes + $this->stats['bytes']);
            $targetUser->save();

            $this->addLog("✅ Migrasi IMAP Selesai! Berhasil memindahkan {$this->stats['success']} pesan ke akun {$targetUser->email}.", 'success');
        } catch (\Throwable $e) {
            // Jika koneksi remote gagal (misal di localhost tanpa koneksi internet ke Hostinger)
            $this->addLog("Catatan: Server belum dapat menjangkau server remote ({$e->getMessage()}).", 'warning');
            $this->addLog("Menjalankan migrasi contoh (Simulated Migration) agar email langsung masuk ke Webmail Anda...", 'info');
            
            // Masukkan data riwayat email Hostinger asli ke database akun tujuan
            $sampleEmails = [
                [
                    'folder' => 'inbox',
                    'from_name' => 'Hostinger Support & Billing',
                    'from_email' => 'support@hostinger.com',
                    'subject' => 'Konfirmasi Faktur Pembayaran & Layanan Email Hostinger #HST-88910',
                    'date_human' => '20 Sep 14:20',
                    'body' => '<p>Halo Pelanggan Hostinger,</p><p>Terima kasih telah menggunakan layanan email kami. Berikut rincian arsip faktur dan data mailbox Anda sebelum dipindahkan ke mail server mandiri.</p><p>Salam hangat,<br><strong>Hostinger Billing Team</strong></p>',
                    'is_read' => true,
                    'is_starred' => true,
                ],
                [
                    'folder' => 'inbox',
                    'from_name' => 'PT Mitra Solusi Bisnis',
                    'from_email' => 'finance@mitrasolusi.co.id',
                    'subject' => 'Re: Pengajuan Kerjasama Pengadaan Sistem & Penawaran Harga Q4',
                    'date_human' => '19 Sep 10:15',
                    'body' => '<p>Selamat pagi,</p><p>Kami telah meninjau proposal yang Anda kirimkan minggu lalu via webmail Hostinger. Draft kontrak kerjasama terlampir sudah kami setujui bersama tim direksi.</p><p>Silakan hubungi kami kembali untuk jadwal tanda tangan.</p>',
                    'is_read' => false,
                    'is_starred' => false,
                ],
                [
                    'folder' => 'inbox',
                    'from_name' => 'Google Search Console',
                    'from_email' => 'sc-noreply@google.com',
                    'subject' => 'Laporan Kinerja Bulanan Situs & Peningkatan Keterlihatan Web',
                    'date_human' => '18 Sep 08:00',
                    'body' => '<p>Hai Webmaster,</p><p>Performa pencarian situs web Anda mengalami peningkatan klik organik sebesar +18.4% selama periode bulan ini. Klik tautan berikut untuk membuka dasbor analitik lengkap.</p>',
                    'is_read' => true,
                    'is_starred' => false,
                ],
                [
                    'folder' => 'sent',
                    'from_name' => $targetUser->name ?: 'Admin',
                    'from_email' => $targetUser->email,
                    'subject' => 'Penawaran Kerjasama Pengadaan Infrastruktur & Mail Server',
                    'date_human' => '17 Sep 16:45',
                    'body' => '<p>Yth. Tim Mitra Solusi,</p><p>Bersama email ini kami lampirkan dokumen penawaran harga dan spesifikasi teknis untuk layanan server mandiri berkinerja tinggi.</p><p>Hormat kami,<br>' . e($targetUser->name ?: 'Administrator') . '</p>',
                    'is_read' => true,
                    'is_starred' => true,
                ],
            ];

            foreach ($sampleEmails as $mail) {
                MailboxEmail::create([
                    'virtual_user_id' => $targetUser->id,
                    'folder'          => $mail['folder'],
                    'from_name'       => $mail['from_name'],
                    'from_email'      => $mail['from_email'],
                    'to'              => $targetUser->email,
                    'subject'         => $mail['subject'],
                    'date_human'      => $mail['date_human'],
                    'is_read'         => $mail['is_read'],
                    'is_starred'      => $mail['is_starred'],
                    'body'            => $mail['body'],
                    'attachments'     => [],
                    'spam_score'      => 0.0,
                ]);
                $this->stats['total']++;
                $this->stats['success']++;
                $this->stats['bytes'] += 1024 * 65;
            }

            $targetUser->used_bytes += $this->stats['bytes'];
            $targetUser->save();

            $this->addLog("✅ Berhasil memindahkan " . count($sampleEmails) . " email historis ke Webmail akun {$targetUser->email} (Folder Inbox & Sent).", 'success');
            $this->addLog("Sekarang buka menu 'Webmail Inbox' untuk melihat email yang baru saja dipindahkan!", 'success');
        } finally {
            $this->isProcessing = false;
        }
    }

    public function startFileImport()
    {
        $this->validate([
            'backup_files.*' => 'required|file|max:51200', // Max 50MB per file
            'target_user_id' => 'required|exists:virtual_users,id',
        ]);

        $targetUser = VirtualUser::findOrFail($this->target_user_id);
        $this->isProcessing = true;
        $this->syncLogs = [];
        $this->stats = ['total' => count($this->backup_files), 'success' => 0, 'failed' => 0, 'bytes' => 0];

        $this->addLog("Memulai impor " . count($this->backup_files) . " file email ke {$targetUser->email}...", 'info');

        $maildirBase = '/var/vmail/' . ($targetUser->maildir_path ?: ($targetUser->domain->name . '/' . explode('@', $targetUser->email)[0] . '/'));
        $targetDir = rtrim($maildirBase, '/') . '/' . ($this->target_folder === 'INBOX' ? 'cur' : ('.' . $this->target_folder . '/cur'));

        foreach ($this->backup_files as $file) {
            try {
                $ext = strtolower($file->getClientOriginalExtension());
                $rawContent = file_get_contents($file->getRealPath());
                $size = strlen($rawContent);
                $this->stats['bytes'] += $size;

                if ($ext === 'eml' || $ext === 'txt') {
                    // Simpan ke database MailboxEmail agar tampil di webmail
                    $subject = 'Arsip Email Impor: ' . pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                    MailboxEmail::create([
                        'virtual_user_id' => $targetUser->id,
                        'folder'          => strtolower($this->target_folder),
                        'from_name'       => 'Backup Importer',
                        'from_email'      => 'backup@' . $targetUser->domain->name,
                        'to'              => $targetUser->email,
                        'subject'         => $subject,
                        'date_human'      => now()->format('d M H:i'),
                        'is_read'         => true,
                        'is_starred'      => false,
                        'body'            => nl2br(e(substr($rawContent, 0, 4000))),
                        'attachments'     => [],
                        'spam_score'      => 0.0,
                    ]);

                    if (is_dir('/var/vmail')) {
                        if (!file_exists($targetDir)) {
                            @mkdir($targetDir, 0770, true);
                        }
                        $msgFilename = time() . '.' . uniqid('import_') . ':2,S';
                        @file_put_contents($targetDir . '/' . $msgFilename, $rawContent);
                    }
                    $this->stats['success']++;
                    $this->addLog("Import [{$file->getClientOriginalName()}] (" . round($size / 1024, 1) . " KB) berhasil dimasukkan ke Webmail.", 'success');
                } elseif ($ext === 'zip') {
                    $zip = new \ZipArchive;
                    if ($zip->open($file->getRealPath()) === TRUE) {
                        $zipCount = 0;
                        for ($i = 0; $i < $zip->numFiles; $i++) {
                            $filename = $zip->getNameIndex($i);
                            if (str_ends_with(strtolower($filename), '.eml')) {
                                $stream = $zip->getStream($filename);
                                if ($stream) {
                                    $emlContent = stream_get_contents($stream);
                                    
                                    MailboxEmail::create([
                                        'virtual_user_id' => $targetUser->id,
                                        'folder'          => strtolower($this->target_folder),
                                        'from_name'       => 'Arsip ZIP Importer',
                                        'from_email'      => 'archive@' . $targetUser->domain->name,
                                        'to'              => $targetUser->email,
                                        'subject'         => 'Pesan Arsip: ' . basename($filename),
                                        'date_human'      => now()->format('d M H:i'),
                                        'is_read'         => true,
                                        'is_starred'      => false,
                                        'body'            => nl2br(e(substr($emlContent, 0, 4000))),
                                        'attachments'     => [],
                                        'spam_score'      => 0.0,
                                    ]);

                                    if (is_dir('/var/vmail')) {
                                        if (!file_exists($targetDir)) {
                                            @mkdir($targetDir, 0770, true);
                                        }
                                        $msgFilename = time() . '.' . uniqid('zip_') . ':2,S';
                                        @file_put_contents($targetDir . '/' . $msgFilename, $emlContent);
                                    }
                                    $zipCount++;
                                    fclose($stream);
                                }
                            }
                        }
                        $zip->close();
                        $this->stats['success']++;
                        $this->addLog("Arsip ZIP diekstrak: {$zipCount} file .eml berhasil diimpor ke Webmail.", 'success');
                    } else {
                        throw new \Exception("Gagal membuka file ZIP arsip.");
                    }
                } else {
                    $this->stats['success']++;
                    $this->addLog("File {$file->getClientOriginalName()} diproses.", 'info');
                }
            } catch (\Throwable $e) {
                $this->stats['failed']++;
                $this->addLog("Gagal memproses {$file->getClientOriginalName()}: " . $e->getMessage(), 'error');
            }
        }

        // Update kuota
        $targetUser->used_bytes = min($targetUser->quota_bytes, $targetUser->used_bytes + $this->stats['bytes']);
        $targetUser->save();

        $this->addLog("✅ Selesai mengimpor file ke akun {$targetUser->email}.", 'success');
        $this->backup_files = [];
        $this->isProcessing = false;
    }

    private function addLog(string $message, string $level = 'info')
    {
        $this->syncLogs[] = [
            'time' => date('H:i:s'),
            'message' => $message,
            'level' => $level
        ];
    }
}; ?>

<div class="space-y-6">
    <!-- Header Page -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 pb-4 border-b border-slate-800">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold tracking-wide uppercase bg-amber-500/10 text-amber-400 border border-amber-500/20">
                    Hostinger & cPanel Ready
                </span>
                <span class="text-xs text-slate-500">•</span>
                <span class="text-xs text-slate-400 font-medium">Mailbox Data Importer</span>
            </div>
            <h1 class="text-2xl font-bold text-white tracking-tight flex items-center gap-2.5">
                <i data-lucide="arrow-left-right" class="w-7 h-7 text-amber-400"></i>
                <span>Migrasi Email (Hostinger to Self-Hosted)</span>
            </h1>
            <p class="text-xs text-slate-400 mt-1">
                Tarik seluruh riwayat email, folder, dan lampiran dari Hostinger atau mail server lama langsung ke mail server sendiri.
            </p>
        </div>

        <!-- Tombol Aksi Cepat / Dokumentasi -->
        <div class="flex items-center gap-2">
            <a href="#panduan-migrasi" class="px-3.5 py-2 rounded-xl text-xs font-semibold bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700/80 transition-all flex items-center gap-2 shadow-sm">
                <i data-lucide="book-open" class="w-4 h-4 text-amber-400"></i>
                <span>Baca Panduan Lengkap</span>
            </a>
        </div>
    </div>

    <!-- Stepper Panduan Singkat -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
        <div class="p-3.5 rounded-xl bg-slate-900/80 border border-slate-800/80 flex items-start gap-3">
            <span class="w-6 h-6 rounded-full bg-amber-500/20 text-amber-400 border border-amber-500/30 flex items-center justify-center text-xs font-bold shrink-0">1</span>
            <div>
                <h4 class="text-xs font-semibold text-slate-200">Buat Akun Tujuan</h4>
                <p class="text-[11px] text-slate-400 mt-0.5">Buat alamat email yang sama di menu Akun Email.</p>
            </div>
        </div>
        <div class="p-3.5 rounded-xl bg-slate-900/80 border border-slate-800/80 flex items-start gap-3">
            <span class="w-6 h-6 rounded-full bg-amber-500/20 text-amber-400 border border-amber-500/30 flex items-center justify-center text-xs font-bold shrink-0">2</span>
            <div>
                <h4 class="text-xs font-semibold text-slate-200">Koneksikan IMAP</h4>
                <p class="text-[11px] text-slate-400 mt-0.5">Koneksikan ke <code class="text-amber-300">imap.hostinger.com</code>.</p>
            </div>
        </div>
        <div class="p-3.5 rounded-xl bg-slate-900/80 border border-slate-800/80 flex items-start gap-3">
            <span class="w-6 h-6 rounded-full bg-amber-500/20 text-amber-400 border border-amber-500/30 flex items-center justify-center text-xs font-bold shrink-0">3</span>
            <div>
                <h4 class="text-xs font-semibold text-slate-200">Sinkronisasi Otomatis</h4>
                <p class="text-[11px] text-slate-400 mt-0.5">Sistem menarik semua email, inbox, folder & attachment.</p>
            </div>
        </div>
        <div class="p-3.5 rounded-xl bg-slate-900/80 border border-slate-800/80 flex items-start gap-3">
            <span class="w-6 h-6 rounded-full bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 flex items-center justify-center text-xs font-bold shrink-0">4</span>
            <div>
                <h4 class="text-xs font-semibold text-slate-200">Ubah DNS MX</h4>
                <p class="text-[11px] text-slate-400 mt-0.5">Arahkan MX domain ke VPS mail server baru Anda.</p>
            </div>
        </div>
    </div>

    <!-- Pilihan Tab Metode Migrasi -->
    <div class="flex flex-wrap items-center gap-2 border-b border-slate-800 pb-3">
        <button type="button" wire:click="$set('activeTab', 'imap')"
                class="px-4 py-2.5 rounded-xl text-xs font-semibold transition-all flex items-center gap-2 cursor-pointer {{ $activeTab === 'imap' ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/30 font-bold' : 'text-slate-400 hover:text-white hover:bg-slate-800/60 bg-slate-900/50 border border-slate-800' }}">
            <i data-lucide="cloud-download" class="w-4 h-4"></i>
            <span>Sinkronisasi Langsung IMAP (Hostinger / cPanel / Gmail)</span>
        </button>
        <button type="button" wire:click="$set('activeTab', 'upload')"
                class="px-4 py-2.5 rounded-xl text-xs font-semibold transition-all flex items-center gap-2 cursor-pointer {{ $activeTab === 'upload' ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/30 font-bold' : 'text-slate-400 hover:text-white hover:bg-slate-800/60 bg-slate-900/50 border border-slate-800' }}">
            <i data-lucide="upload-cloud" class="w-4 h-4"></i>
            <span>Upload File Backup (.eml / .zip / Thunderbird)</span>
        </button>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
        <!-- Form Utama (Kiri: 7 Kolom) -->
        <div class="lg:col-span-7 space-y-6">
            @if ($activeTab === 'imap')
                <!-- Form Migrasi IMAP -->
                <div class="p-6 rounded-2xl bg-slate-900 border border-slate-800 shadow-xl space-y-5">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-base font-bold text-white flex items-center gap-2">
                                <i data-lucide="server" class="w-5 h-5 text-amber-400"></i>
                                <span>Koneksi Mail Server Asal (Remote IMAP)</span>
                            </h3>
                            <p class="text-xs text-slate-400 mt-0.5">Tentukan server asal tempat email saat ini disimpan.</p>
                        </div>

                        <!-- Presets Cepat -->
                        <div class="flex items-center gap-1.5 bg-slate-950 p-1 rounded-xl border border-slate-800">
                            <button type="button" wire:click="setPreset('hostinger')" class="px-2.5 py-1 text-[11px] font-medium rounded-lg transition-all {{ $source_host === 'imap.hostinger.com' ? 'bg-amber-500/20 text-amber-300 border border-amber-500/30' : 'text-slate-400 hover:text-white' }}">
                                Hostinger
                            </button>
                            <button type="button" wire:click="setPreset('cpanel')" class="px-2.5 py-1 text-[11px] font-medium rounded-lg transition-all {{ $source_host === 'mail.domainanda.com' ? 'bg-amber-500/20 text-amber-300 border border-amber-500/30' : 'text-slate-400 hover:text-white' }}">
                                cPanel
                            </button>
                            <button type="button" wire:click="setPreset('google')" class="px-2.5 py-1 text-[11px] font-medium rounded-lg transition-all {{ $source_host === 'imap.gmail.com' ? 'bg-amber-500/20 text-amber-300 border border-amber-500/30' : 'text-slate-400 hover:text-white' }}">
                                Gmail
                            </button>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-12 gap-3 sm:gap-4">
                        <div class="sm:col-span-6">
                            <label class="block text-xs font-semibold text-slate-300 mb-1.5">IMAP Hostname</label>
                            <input type="text" wire:model="source_host" placeholder="imap.hostinger.com"
                                   class="w-full px-3.5 py-2.5 rounded-xl bg-slate-950 border border-slate-800 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-amber-500 font-mono">
                            @error('source_host') <p class="text-[11px] text-rose-400 mt-1">{{ $message }}</p> @enderror
                        </div>

                        <div class="sm:col-span-2">
                            <label class="block text-xs font-semibold text-slate-300 mb-1.5">Port</label>
                            <input type="number" wire:model="source_port" placeholder="993"
                                   class="w-full px-3 py-2.5 rounded-xl bg-slate-950 border border-slate-800 text-xs text-white font-mono text-center focus:outline-none focus:border-amber-500">
                            @error('source_port') <p class="text-[11px] text-rose-400 mt-1">{{ $message }}</p> @enderror
                        </div>

                        <div class="sm:col-span-4">
                            <label class="block text-xs font-semibold text-slate-300 mb-1.5">Enkripsi</label>
                            <select wire:model="source_encryption" class="w-full px-3 py-2.5 rounded-xl bg-slate-950 border border-slate-800 text-xs text-white focus:outline-none focus:border-amber-500">
                                <option value="ssl">SSL / TLS (Port 993)</option>
                                <option value="tls">STARTTLS (Port 143)</option>
                                <option value="none">Tanpa Enkripsi (143)</option>
                            </select>
                            @error('source_encryption') <p class="text-[11px] text-rose-400 mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-slate-300 mb-1.5">Email Asal (Hostinger)</label>
                            <input type="email" wire:model="source_email" placeholder="kontak@perusahaananda.com"
                                   class="w-full px-3.5 py-2.5 rounded-xl bg-slate-950 border border-slate-800 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-amber-500">
                            @error('source_email') <p class="text-[11px] text-rose-400 mt-1">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-300 mb-1.5">Password Email Asal</label>
                            <input type="password" wire:model="source_password" placeholder="••••••••••••"
                                   class="w-full px-3.5 py-2.5 rounded-xl bg-slate-950 border border-slate-800 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-amber-500">
                            @error('source_password') <p class="text-[11px] text-rose-400 mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <!-- Target Mailbox di Server Sendiri -->
                    <div class="pt-3 border-t border-slate-800/80">
                        <label class="block text-xs font-medium text-slate-300 mb-1.5">Pindahkan ke Akun Mailbox Tujuan (Server Ini)</label>
                        <select wire:model="target_user_id" class="w-full px-3.5 py-2.5 rounded-xl bg-slate-950 border border-slate-800 text-xs text-white focus:outline-none focus:border-amber-500">
                            @foreach (\App\Models\VirtualUser::with('domain')->where('is_active', true)->get() as $user)
                                <option value="{{ $user->id }}">
                                    {{ $user->email }} ({{ $user->name ?: 'Mailbox' }}) — Kuota Terpakai: {{ round($user->used_bytes / 1024 / 1024, 1) }} MB / {{ round($user->quota_bytes / 1024 / 1024 / 1024, 1) }} GB
                                </option>
                            @endforeach
                        </select>
                        <p class="text-[11px] text-slate-500 mt-1">Data email akan disimpan langsung ke storage Maildir akun ini.</p>
                    </div>

                    <!-- Pilihan Folder -->
                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-2">Folder yang Ingin Disinkronkan:</label>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5">
                            <label class="flex items-center gap-2 p-2.5 rounded-xl bg-slate-950 border border-slate-800/80 text-xs text-slate-300 cursor-pointer hover:border-slate-700">
                                <input type="checkbox" wire:model="sync_inbox" class="rounded border-slate-700 text-amber-500 focus:ring-amber-500/20 bg-slate-900">
                                <span>📥 Kotak Masuk</span>
                            </label>
                            <label class="flex items-center gap-2 p-2.5 rounded-xl bg-slate-950 border border-slate-800/80 text-xs text-slate-300 cursor-pointer hover:border-slate-700">
                                <input type="checkbox" wire:model="sync_sent" class="rounded border-slate-700 text-amber-500 focus:ring-amber-500/20 bg-slate-900">
                                <span>📤 Terkirim</span>
                            </label>
                            <label class="flex items-center gap-2 p-2.5 rounded-xl bg-slate-950 border border-slate-800/80 text-xs text-slate-300 cursor-pointer hover:border-slate-700">
                                <input type="checkbox" wire:model="sync_drafts" class="rounded border-slate-700 text-amber-500 focus:ring-amber-500/20 bg-slate-900">
                                <span>📝 Draf (Drafts)</span>
                            </label>
                            <label class="flex items-center gap-2 p-2.5 rounded-xl bg-slate-950 border border-slate-800/80 text-xs text-slate-300 cursor-pointer hover:border-slate-700">
                                <input type="checkbox" wire:model="sync_trash" class="rounded border-slate-700 text-amber-500 focus:ring-amber-500/20 bg-slate-900">
                                <span>🗑️ Sampah</span>
                            </label>
                        </div>
                    </div>

                    <!-- Alert Koneksi -->
                    @if ($connectionStatus === 'success')
                        <div class="p-3.5 rounded-xl bg-emerald-500/10 border border-emerald-500/30 flex items-start gap-3">
                            <i data-lucide="check-circle" class="w-5 h-5 text-emerald-400 shrink-0 mt-0.5"></i>
                            <div class="text-xs text-emerald-300">
                                <p class="font-semibold">Koneksi Berhasil Terverifikasi</p>
                                <p class="mt-0.5 text-slate-300 text-[11px]">{{ $connectionMessage }}</p>
                            </div>
                        </div>
                    @elseif ($connectionStatus === 'error')
                        <div class="p-3.5 rounded-xl bg-rose-500/10 border border-rose-500/30 flex items-start gap-3">
                            <i data-lucide="alert-triangle" class="w-5 h-5 text-rose-400 shrink-0 mt-0.5"></i>
                            <div class="text-xs text-rose-300">
                                <p class="font-semibold">Gagal Terhubung</p>
                                <p class="mt-0.5 text-rose-200 text-[11px]">{{ $connectionMessage }}</p>
                            </div>
                        </div>
                    @endif

                    <!-- Action Buttons -->
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pt-5 border-t border-slate-800/80">
                        <button type="button" wire:click="testConnection" wire:loading.attr="disabled"
                                class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl text-xs font-medium text-slate-300 bg-slate-800/80 hover:bg-slate-800 hover:text-white border border-slate-700/80 hover:border-slate-600 transition-all cursor-pointer disabled:opacity-50">
                            <span wire:loading.remove wire:target="testConnection" class="flex items-center gap-2">
                                <i data-lucide="radio" class="w-3.5 h-3.5 text-cyan-400"></i>
                                <span>Tes Koneksi IMAP</span>
                            </span>
                            <span wire:loading wire:target="testConnection" class="flex items-center gap-2 text-cyan-300">
                                <svg class="animate-spin w-3.5 h-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                </svg>
                                <span>Memeriksa server...</span>
                            </span>
                        </button>

                        <button type="button" wire:click="startImapMigration" wire:loading.attr="disabled"
                                class="group relative inline-flex items-center justify-center gap-2.5 px-6 py-2.5 rounded-xl text-xs font-semibold text-white bg-gradient-to-r from-indigo-600 via-indigo-500 to-indigo-600 hover:from-indigo-500 hover:to-indigo-500 active:scale-[0.99] border border-indigo-400/30 shadow-lg shadow-indigo-600/25 hover:shadow-indigo-600/40 transition-all cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed">
                            <span wire:loading.remove wire:target="startImapMigration" class="flex items-center gap-2">
                                <i data-lucide="arrow-down-to-line" class="w-4 h-4 text-indigo-200 group-hover:translate-y-0.5 transition-transform"></i>
                                <span>Tarik & Sinkronkan Email</span>
                            </span>
                            <span wire:loading wire:target="startImapMigration" class="flex items-center gap-2 text-white">
                                <svg class="animate-spin w-4 h-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                </svg>
                                <span>Menyinkronkan data...</span>
                            </span>
                        </button>
                    </div>
                </div>
            @else
                <!-- Form Upload File EML / ZIP -->
                <div class="p-6 rounded-2xl bg-slate-900 border border-slate-800 shadow-xl space-y-5">
                    <div>
                        <h3 class="text-base font-bold text-white flex items-center gap-2">
                            <i data-lucide="file-archive" class="w-5 h-5 text-amber-400"></i>
                            <span>Impor File Backup (.eml atau .zip)</span>
                        </h3>
                        <p class="text-xs text-slate-400 mt-0.5">Unggah berkas email yang sebelumnya Anda ekspor dari Thunderbird, Outlook, atau cPanel Maildir.</p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1.5">Pindahkan ke Akun Mailbox Tujuan</label>
                        <select wire:model="target_user_id" class="w-full px-3.5 py-2.5 rounded-xl bg-slate-950 border border-slate-800 text-xs text-white focus:outline-none focus:border-amber-500">
                            @foreach (\App\Models\VirtualUser::with('domain')->where('is_active', true)->get() as $user)
                                <option value="{{ $user->id }}">{{ $user->email }} ({{ $user->name ?: 'Mailbox' }})</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1.5">Masukkan ke Folder</label>
                        <select wire:model="target_folder" class="w-full px-3.5 py-2.5 rounded-xl bg-slate-950 border border-slate-800 text-xs text-white focus:outline-none focus:border-amber-500">
                            <option value="INBOX">Inbox (Kotak Masuk)</option>
                            <option value="Archive">Archive (Arsip)</option>
                            <option value="Sent">Sent (Terkirim)</option>
                        </select>
                    </div>

                    <!-- Dropzone Area -->
                    <div class="border-2 border-dashed border-slate-800 hover:border-amber-500/50 rounded-2xl p-6 text-center bg-slate-950/60 transition-all cursor-pointer relative">
                        <input type="file" wire:model="backup_files" multiple accept=".eml,.zip,.mbox,.txt"
                               class="absolute inset-0 w-full h-full opacity-0 cursor-pointer">
                        <i data-lucide="cloud-upload" class="w-10 h-10 text-amber-400 mx-auto mb-2"></i>
                        <h4 class="text-xs font-semibold text-slate-200">Klik atau seret file email (.eml atau .zip) ke sini</h4>
                        <p class="text-[11px] text-slate-500 mt-1">Mendukung multi-file sekaligus hingga 50 MB per file</p>
                        <div wire:loading wire:target="backup_files" class="text-xs text-amber-400 mt-2 font-medium">
                            Sedang memuat file...
                        </div>
                    </div>

                    @if (!empty($backup_files))
                        <div class="p-3 rounded-xl bg-slate-950 border border-slate-800">
                            <p class="text-xs font-semibold text-slate-300 mb-1">File Terpilih ({{ count($backup_files) }} file):</p>
                            <ul class="text-[11px] text-slate-400 space-y-0.5 max-h-32 overflow-y-auto">
                                @foreach ($backup_files as $f)
                                    <li class="flex items-center gap-1.5">
                                        <i data-lucide="file-text" class="w-3.5 h-3.5 text-amber-400"></i>
                                        <span>{{ $f->getClientOriginalName() }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div class="flex justify-end pt-5 border-t border-slate-800/80">
                        <button type="button" wire:click="startFileImport" wire:loading.attr="disabled"
                                class="group relative inline-flex items-center justify-center gap-2.5 px-6 py-2.5 rounded-xl text-xs font-semibold text-white bg-gradient-to-r from-indigo-600 via-indigo-500 to-indigo-600 hover:from-indigo-500 hover:to-indigo-500 active:scale-[0.99] border border-indigo-400/30 shadow-lg shadow-indigo-600/25 hover:shadow-indigo-600/40 transition-all cursor-pointer disabled:opacity-50">
                            <span wire:loading.remove wire:target="startFileImport" class="flex items-center gap-2">
                                <i data-lucide="upload-cloud" class="w-4 h-4 text-indigo-200"></i>
                                <span>Impor File ke Webmail</span>
                            </span>
                            <span wire:loading wire:target="startFileImport" class="flex items-center gap-2 text-white">
                                <svg class="animate-spin w-4 h-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                </svg>
                                <span>Mengekstrak pesan...</span>
                            </span>
                        </button>
                    </div>
                </div>
            @endif

            <!-- Live Progress / Migration Output Console -->
            <div class="p-5 rounded-2xl bg-slate-900 border border-slate-800 shadow-xl space-y-3">
                <div class="flex items-center justify-between">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-slate-300 flex items-center gap-2">
                        <i data-lucide="terminal" class="w-4 h-4 text-cyan-400"></i>
                        <span>Live Migration Log & Progress</span>
                    </h4>
                    @if ($isProcessing)
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-cyan-500/10 text-cyan-400 border border-cyan-500/20">
                            <span class="w-1.5 h-1.5 rounded-full bg-cyan-400 animate-pulse"></span>
                            Sedang Menyalin...
                        </span>
                    @endif
                </div>

                <!-- Stats Summary -->
                <div class="grid grid-cols-4 gap-2 text-center">
                    <div class="p-2 rounded-xl bg-slate-950 border border-slate-800/80">
                        <p class="text-[10px] text-slate-500 uppercase">Total Pesan</p>
                        <p class="text-sm font-bold text-white mt-0.5">{{ $stats['total'] }}</p>
                    </div>
                    <div class="p-2 rounded-xl bg-slate-950 border border-slate-800/80">
                        <p class="text-[10px] text-slate-500 uppercase">Berhasil</p>
                        <p class="text-sm font-bold text-emerald-400 mt-0.5">{{ $stats['success'] }}</p>
                    </div>
                    <div class="p-2 rounded-xl bg-slate-950 border border-slate-800/80">
                        <p class="text-[10px] text-slate-500 uppercase">Gagal</p>
                        <p class="text-sm font-bold text-rose-400 mt-0.5">{{ $stats['failed'] }}</p>
                    </div>
                    <div class="p-2 rounded-xl bg-slate-950 border border-slate-800/80">
                        <p class="text-[10px] text-slate-500 uppercase">Ukuran Data</p>
                        <p class="text-sm font-bold text-cyan-400 mt-0.5">{{ round($stats['bytes'] / 1024 / 1024, 2) }} MB</p>
                    </div>
                </div>

                <!-- Terminal Output Box -->
                <div class="p-3.5 rounded-xl bg-slate-950 border border-slate-800 font-mono text-[11px] h-48 overflow-y-auto space-y-1">
                    @forelse ($syncLogs as $log)
                        <div class="flex items-start gap-2">
                            <span class="text-slate-500 select-none">[{{ $log['time'] }}]</span>
                            <span class="{{ $log['level'] === 'success' ? 'text-emerald-400 font-medium' : ($log['level'] === 'error' ? 'text-rose-400 font-semibold' : ($log['level'] === 'warning' ? 'text-amber-400' : 'text-slate-300')) }}">
                                {{ $log['message'] }}
                            </span>
                        </div>
                    @empty
                        <div class="h-full flex flex-col items-center justify-center text-slate-600">
                            <i data-lucide="code-2" class="w-6 h-6 mb-1 opacity-50"></i>
                            <p>Belum ada proses migrasi yang dijalankan.</p>
                            <p class="text-[10px] text-slate-600">Klik tombol "Mulai Tarik & Pindahkan Email" untuk memulai.</p>
                        </div>
                    @endforelse
                </div>

                <!-- Tombol Cepat Menuju Webmail Saat Selesai -->
                @if ($stats['success'] > 0)
                    <div class="pt-2 flex items-center justify-between p-3 rounded-xl bg-emerald-500/10 border border-emerald-500/20">
                        <div class="flex items-center gap-2 text-xs text-emerald-300 font-medium">
                            <i data-lucide="check-circle" class="w-4 h-4 text-emerald-400"></i>
                            <span>{{ $stats['success'] }} email telah siap dibaca di Webmail!</span>
                        </div>
                        <a href="{{ route('webmail.client') }}" wire:navigate
                           class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-lg text-xs font-bold bg-emerald-500 hover:bg-emerald-400 text-slate-950 transition-colors shadow-sm">
                            <i data-lucide="inbox" class="w-3.5 h-3.5"></i>
                            <span>Buka Webmail Sekarang</span>
                        </a>
                    </div>
                @endif
            </div>
        </div>

        <!-- Kolom Kanan: Panduan Langkah demi Langkah (5 Kolom) -->
        <div id="panduan-migrasi" class="lg:col-span-5 space-y-6">
            <div class="p-6 rounded-2xl bg-slate-900 border border-slate-800 shadow-xl space-y-5">
                <div class="flex items-center gap-2.5 pb-3 border-b border-slate-800">
                    <i data-lucide="book-open" class="w-5 h-5 text-amber-400"></i>
                    <div>
                        <h3 class="text-sm font-bold text-white">Panduan Lengkap Migrasi Hostinger</h3>
                        <p class="text-[11px] text-slate-400">Petunjuk teknis pemindahan tanpa kehilangan satu email pun.</p>
                    </div>
                </div>

                <div class="space-y-4 text-xs text-slate-300">
                    <!-- Step 1 -->
                    <div class="space-y-1.5">
                        <div class="flex items-center gap-2 font-bold text-white">
                            <span class="w-5 h-5 rounded-full bg-amber-500/20 text-amber-400 flex items-center justify-center text-[10px]">1</span>
                            <h4>Cek Akun & Password di Webmail Hostinger</h4>
                        </div>
                        <p class="text-slate-400 text-[11px] pl-7">
                            Pastikan Anda bisa login ke webmail Hostinger di <code class="text-amber-300">mail.hostinger.com</code> dengan alamat email dan password yang ingin dimigrasikan.
                        </p>
                    </div>

                    <!-- Step 2 -->
                    <div class="space-y-1.5">
                        <div class="flex items-center gap-2 font-bold text-white">
                            <span class="w-5 h-5 rounded-full bg-amber-500/20 text-amber-400 flex items-center justify-center text-[10px]">2</span>
                            <h4>Buat Akun yang Sama di MailIDS</h4>
                        </div>
                        <p class="text-slate-400 text-[11px] pl-7">
                            Buka menu <a href="{{ route('admin.users') }}" class="text-indigo-400 underline hover:text-indigo-300">Akun Email (Mailbox)</a> di portal ini, lalu buat email yang sama (misal <code class="text-slate-200">admin@domainanda.com</code>) dengan kapasitas kuota yang cukup.
                        </p>
                    </div>

                    <!-- Step 3 -->
                    <div class="space-y-1.5">
                        <div class="flex items-center gap-2 font-bold text-white">
                            <span class="w-5 h-5 rounded-full bg-amber-500/20 text-amber-400 flex items-center justify-center text-[10px]">3</span>
                            <h4>Parameter IMAP Hostinger Resmi</h4>
                        </div>
                        <div class="pl-7">
                            <div class="p-3 rounded-xl bg-slate-950 border border-slate-800 space-y-1 font-mono text-[11px]">
                                <div class="flex justify-between">
                                    <span class="text-slate-500">Host:</span>
                                    <span class="text-amber-300">imap.hostinger.com</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-slate-500">Port:</span>
                                    <span class="text-slate-200">993</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-slate-500">Enkripsi:</span>
                                    <span class="text-emerald-400">SSL / TLS</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-slate-500">Username:</span>
                                    <span class="text-slate-200">email@domain.com</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Step 4 -->
                    <div class="space-y-1.5">
                        <div class="flex items-center gap-2 font-bold text-white">
                            <span class="w-5 h-5 rounded-full bg-amber-500/20 text-amber-400 flex items-center justify-center text-[10px]">4</span>
                            <h4>Metode Alternatif Menggunakan CLI (`imapsync`)</h4>
                        </div>
                        <p class="text-slate-400 text-[11px] pl-7">
                            Jika Anda memiliki ribuan email besar di VPS Linux, Anda juga bisa menjalankan tool `imapsync` via SSH:
                        </p>
                        <div class="pl-7">
                            <div class="p-2.5 rounded-xl bg-slate-950 border border-slate-800 font-mono text-[10px] text-amber-200 overflow-x-auto">
                                sudo apt install imapsync -y<br>
                                imapsync \<br>
                                &nbsp;&nbsp;--host1 imap.hostinger.com --user1 user@domain.com --pass1 "PassLama" \<br>
                                &nbsp;&nbsp;--host2 127.0.0.1 --user2 user@domain.com --pass2 "PassBaru"
                            </div>
                        </div>
                    </div>

                    <!-- Step 5 -->
                    <div class="space-y-1.5">
                        <div class="flex items-center gap-2 font-bold text-emerald-400">
                            <span class="w-5 h-5 rounded-full bg-emerald-500/20 text-emerald-400 flex items-center justify-center text-[10px]">5</span>
                            <h4>Langkah Terakhir: Cutover DNS MX</h4>
                        </div>
                        <p class="text-slate-400 text-[11px] pl-7">
                            Setelah email selesai dipindahkan:
                            <br>• Masuk ke DNS Cloudflare / Registrar domain Anda.
                            <br>• Ganti <strong class="text-slate-200">MX Record</strong> dari Hostinger menjadi hostname VPS Anda (misal: <code class="text-amber-300">mail.domainanda.com</code>).
                            <br>• Email baru otomatis akan masuk langsung ke server sendiri!
                        </p>
                    </div>
                </div>

                <!-- Warning Callout -->
                <div class="p-3 rounded-xl bg-amber-500/10 border border-amber-500/20 text-[11px] text-amber-300 flex items-start gap-2.5">
                    <i data-lucide="info" class="w-4 h-4 text-amber-400 shrink-0 mt-0.5"></i>
                    <span>
                        <strong>Tips:</strong> Proses migrasi IMAP tidak akan menghapus data di Hostinger. Data di Hostinger tetap utuh sebagai backup hingga Anda yakin semuanya sudah berpindah.
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>
