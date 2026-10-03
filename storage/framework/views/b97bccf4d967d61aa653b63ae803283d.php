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
?>

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
    <div class="flex items-center gap-2 border-b border-slate-800 pb-2">
        <button type="button" wire:click="$set('activeTab', 'imap')"
                class="px-4 py-2 rounded-xl text-xs font-semibold transition-all flex items-center gap-2 <?php echo e($activeTab === 'imap' ? 'bg-amber-500 text-slate-950 shadow-md shadow-amber-500/20 font-bold' : 'text-slate-400 hover:text-white hover:bg-slate-800/60'); ?>">
            <i data-lucide="cloud-download" class="w-4 h-4"></i>
            <span>Sinkronisasi Langsung IMAP (Hostinger / cPanel / Gmail)</span>
        </button>
        <button type="button" wire:click="$set('activeTab', 'upload')"
                class="px-4 py-2 rounded-xl text-xs font-semibold transition-all flex items-center gap-2 <?php echo e($activeTab === 'upload' ? 'bg-amber-500 text-slate-950 shadow-md shadow-amber-500/20 font-bold' : 'text-slate-400 hover:text-white hover:bg-slate-800/60'); ?>">
            <i data-lucide="upload-cloud" class="w-4 h-4"></i>
            <span>Upload File Backup (.eml / .zip / Thunderbird)</span>
        </button>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
        <!-- Form Utama (Kiri: 7 Kolom) -->
        <div class="lg:col-span-7 space-y-6">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($activeTab === 'imap'): ?>
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
                            <button type="button" wire:click="setPreset('hostinger')" class="px-2.5 py-1 text-[11px] font-medium rounded-lg transition-all <?php echo e($source_host === 'imap.hostinger.com' ? 'bg-amber-500/20 text-amber-300 border border-amber-500/30' : 'text-slate-400 hover:text-white'); ?>">
                                Hostinger
                            </button>
                            <button type="button" wire:click="setPreset('cpanel')" class="px-2.5 py-1 text-[11px] font-medium rounded-lg transition-all <?php echo e($source_host === 'mail.domainanda.com' ? 'bg-amber-500/20 text-amber-300 border border-amber-500/30' : 'text-slate-400 hover:text-white'); ?>">
                                cPanel
                            </button>
                            <button type="button" wire:click="setPreset('google')" class="px-2.5 py-1 text-[11px] font-medium rounded-lg transition-all <?php echo e($source_host === 'imap.gmail.com' ? 'bg-amber-500/20 text-amber-300 border border-amber-500/30' : 'text-slate-400 hover:text-white'); ?>">
                                Gmail
                            </button>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="md:col-span-2">
                            <label class="block text-xs font-medium text-slate-300 mb-1.5">IMAP Hostname</label>
                            <input type="text" wire:model="source_host" placeholder="imap.hostinger.com"
                                   class="w-full px-3.5 py-2.5 rounded-xl bg-slate-950 border border-slate-800 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-amber-500 font-mono">
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['source_host'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <p class="text-[11px] text-rose-400 mt-1"><?php echo e($message); ?></p> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-300 mb-1.5">Port & Enkripsi</label>
                            <div class="flex gap-2">
                                <input type="number" wire:model="source_port" placeholder="993"
                                       class="w-20 px-3 py-2.5 rounded-xl bg-slate-950 border border-slate-800 text-xs text-white font-mono text-center focus:outline-none focus:border-amber-500">
                                <select wire:model="source_encryption" class="flex-1 px-3 py-2.5 rounded-xl bg-slate-950 border border-slate-800 text-xs text-white focus:outline-none focus:border-amber-500">
                                    <option value="ssl">SSL / TLS (993)</option>
                                    <option value="tls">STARTTLS (143)</option>
                                    <option value="none">Tanpa Enkripsi</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-slate-300 mb-1.5">Email Asal (Hostinger)</label>
                            <input type="email" wire:model="source_email" placeholder="kontak@perusahaananda.com"
                                   class="w-full px-3.5 py-2.5 rounded-xl bg-slate-950 border border-slate-800 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-amber-500">
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['source_email'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <p class="text-[11px] text-rose-400 mt-1"><?php echo e($message); ?></p> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-300 mb-1.5">Password Email Asal</label>
                            <input type="password" wire:model="source_password" placeholder="••••••••••••"
                                   class="w-full px-3.5 py-2.5 rounded-xl bg-slate-950 border border-slate-800 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-amber-500">
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['source_password'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <p class="text-[11px] text-rose-400 mt-1"><?php echo e($message); ?></p> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>
                    </div>

                    <!-- Target Mailbox di Server Sendiri -->
                    <div class="pt-3 border-t border-slate-800/80">
                        <label class="block text-xs font-medium text-slate-300 mb-1.5">Pindahkan ke Akun Mailbox Tujuan (Server Ini)</label>
                        <select wire:model="target_user_id" class="w-full px-3.5 py-2.5 rounded-xl bg-slate-950 border border-slate-800 text-xs text-white focus:outline-none focus:border-amber-500">
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = \App\Models\VirtualUser::with('domain')->where('is_active', true)->get(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $user): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                                <option value="<?php echo e($user->id); ?>">
                                    <?php echo e($user->email); ?> (<?php echo e($user->name ?: 'Mailbox'); ?>) — Kuota Terpakai: <?php echo e(round($user->used_bytes / 1024 / 1024, 1)); ?> MB / <?php echo e(round($user->quota_bytes / 1024 / 1024 / 1024, 1)); ?> GB
                                </option>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
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
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($connectionStatus === 'success'): ?>
                        <div class="p-3.5 rounded-xl bg-emerald-500/10 border border-emerald-500/30 flex items-start gap-3">
                            <i data-lucide="check-circle" class="w-5 h-5 text-emerald-400 shrink-0 mt-0.5"></i>
                            <div class="text-xs text-emerald-300">
                                <p class="font-semibold">Koneksi Berhasil Terverifikasi</p>
                                <p class="mt-0.5 text-slate-300 text-[11px]"><?php echo e($connectionMessage); ?></p>
                            </div>
                        </div>
                    <?php elseif($connectionStatus === 'error'): ?>
                        <div class="p-3.5 rounded-xl bg-rose-500/10 border border-rose-500/30 flex items-start gap-3">
                            <i data-lucide="alert-triangle" class="w-5 h-5 text-rose-400 shrink-0 mt-0.5"></i>
                            <div class="text-xs text-rose-300">
                                <p class="font-semibold">Gagal Terhubung</p>
                                <p class="mt-0.5 text-rose-200 text-[11px]"><?php echo e($connectionMessage); ?></p>
                            </div>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

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
            <?php else: ?>
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
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = \App\Models\VirtualUser::with('domain')->where('is_active', true)->get(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $user): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                                <option value="<?php echo e($user->id); ?>"><?php echo e($user->email); ?> (<?php echo e($user->name ?: 'Mailbox'); ?>)</option>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
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

                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!empty($backup_files)): ?>
                        <div class="p-3 rounded-xl bg-slate-950 border border-slate-800">
                            <p class="text-xs font-semibold text-slate-300 mb-1">File Terpilih (<?php echo e(count($backup_files)); ?> file):</p>
                            <ul class="text-[11px] text-slate-400 space-y-0.5 max-h-32 overflow-y-auto">
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $backup_files; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $f): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                                    <li class="flex items-center gap-1.5">
                                        <i data-lucide="file-text" class="w-3.5 h-3.5 text-amber-400"></i>
                                        <span><?php echo e($f->getClientOriginalName()); ?></span>
                                    </li>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
                            </ul>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

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
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

            <!-- Live Progress / Migration Output Console -->
            <div class="p-5 rounded-2xl bg-slate-900 border border-slate-800 shadow-xl space-y-3">
                <div class="flex items-center justify-between">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-slate-300 flex items-center gap-2">
                        <i data-lucide="terminal" class="w-4 h-4 text-cyan-400"></i>
                        <span>Live Migration Log & Progress</span>
                    </h4>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($isProcessing): ?>
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-cyan-500/10 text-cyan-400 border border-cyan-500/20">
                            <span class="w-1.5 h-1.5 rounded-full bg-cyan-400 animate-pulse"></span>
                            Sedang Menyalin...
                        </span>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>

                <!-- Stats Summary -->
                <div class="grid grid-cols-4 gap-2 text-center">
                    <div class="p-2 rounded-xl bg-slate-950 border border-slate-800/80">
                        <p class="text-[10px] text-slate-500 uppercase">Total Pesan</p>
                        <p class="text-sm font-bold text-white mt-0.5"><?php echo e($stats['total']); ?></p>
                    </div>
                    <div class="p-2 rounded-xl bg-slate-950 border border-slate-800/80">
                        <p class="text-[10px] text-slate-500 uppercase">Berhasil</p>
                        <p class="text-sm font-bold text-emerald-400 mt-0.5"><?php echo e($stats['success']); ?></p>
                    </div>
                    <div class="p-2 rounded-xl bg-slate-950 border border-slate-800/80">
                        <p class="text-[10px] text-slate-500 uppercase">Gagal</p>
                        <p class="text-sm font-bold text-rose-400 mt-0.5"><?php echo e($stats['failed']); ?></p>
                    </div>
                    <div class="p-2 rounded-xl bg-slate-950 border border-slate-800/80">
                        <p class="text-[10px] text-slate-500 uppercase">Ukuran Data</p>
                        <p class="text-sm font-bold text-cyan-400 mt-0.5"><?php echo e(round($stats['bytes'] / 1024 / 1024, 2)); ?> MB</p>
                    </div>
                </div>

                <!-- Terminal Output Box -->
                <div class="p-3.5 rounded-xl bg-slate-950 border border-slate-800 font-mono text-[11px] h-48 overflow-y-auto space-y-1">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $syncLogs; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $log): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                        <div class="flex items-start gap-2">
                            <span class="text-slate-500 select-none">[<?php echo e($log['time']); ?>]</span>
                            <span class="<?php echo e($log['level'] === 'success' ? 'text-emerald-400 font-medium' : ($log['level'] === 'error' ? 'text-rose-400 font-semibold' : ($log['level'] === 'warning' ? 'text-amber-400' : 'text-slate-300'))); ?>">
                                <?php echo e($log['message']); ?>

                            </span>
                        </div>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
                        <div class="h-full flex flex-col items-center justify-center text-slate-600">
                            <i data-lucide="code-2" class="w-6 h-6 mb-1 opacity-50"></i>
                            <p>Belum ada proses migrasi yang dijalankan.</p>
                            <p class="text-[10px] text-slate-600">Klik tombol "Mulai Tarik & Pindahkan Email" untuk memulai.</p>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>

                <!-- Tombol Cepat Menuju Webmail Saat Selesai -->
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($stats['success'] > 0): ?>
                    <div class="pt-2 flex items-center justify-between p-3 rounded-xl bg-emerald-500/10 border border-emerald-500/20">
                        <div class="flex items-center gap-2 text-xs text-emerald-300 font-medium">
                            <i data-lucide="check-circle" class="w-4 h-4 text-emerald-400"></i>
                            <span><?php echo e($stats['success']); ?> email telah siap dibaca di Webmail!</span>
                        </div>
                        <a href="<?php echo e(route('webmail.client')); ?>" wire:navigate
                           class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-lg text-xs font-bold bg-emerald-500 hover:bg-emerald-400 text-slate-950 transition-colors shadow-sm">
                            <i data-lucide="inbox" class="w-3.5 h-3.5"></i>
                            <span>Buka Webmail Sekarang</span>
                        </a>
                    </div>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
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
                            Buka menu <a href="<?php echo e(route('admin.users')); ?>" class="text-indigo-400 underline hover:text-indigo-300">Akun Email (Mailbox)</a> di portal ini, lalu buat email yang sama (misal <code class="text-slate-200">admin@domainanda.com</code>) dengan kapasitas kuota yang cukup.
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
</div><?php /**PATH D:\AI Code\mailids\storage\framework\views/livewire/views/65613309.blade.php ENDPATH**/ ?>