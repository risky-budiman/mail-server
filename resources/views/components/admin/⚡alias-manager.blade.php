<?php

use Livewire\Component;
use App\Models\VirtualAlias;
use App\Models\VirtualDomain;
use App\Models\VirtualUser;

new class extends Component
{
    public $domain_id = '';
    public $source_prefix = '';
    public $destination_email = '';
    public $keep_local_copy = false;
    public $is_active = true;
    public $search = '';

    // State Edit Modal
    public $showEditModal = false;
    public $editingAliasId = null;
    public $editSourcePrefix = '';
    public $editDomainId = '';
    public $editDestinationEmail = '';
    public $editKeepLocalCopy = false;
    public $editIsActive = true;

    public function mount()
    {
        $firstDomain = VirtualDomain::where('is_active', true)->first();
        if ($firstDomain) {
            $this->domain_id = $firstDomain->id;
        }
    }

    public function createAlias()
    {
        $this->validate([
            'source_prefix' => 'required|string|regex:/^[a-zA-Z0-9._-]+$/',
            'domain_id' => 'required|exists:virtual_domains,id',
            'destination_email' => 'required|string',
        ], [
            'source_prefix.regex' => 'Format alias depan tidak valid (contoh: info, support, billing).',
        ]);

        $domain = VirtualDomain::findOrFail($this->domain_id);
        $fullSourceEmail = strtolower(trim($this->source_prefix)) . '@' . $domain->name;

        // Parse destinasi email (mendukung multi-tujuan dipisah koma)
        $rawDestList = array_filter(array_map('trim', explode(',', strtolower($this->destination_email))));
        if (empty($rawDestList)) {
            $this->addError('destination_email', 'Masukkan minimal satu alamat email tujuan pengalihan yang valid.');
            return;
        }

        $destList = [];
        foreach ($rawDestList as $dest) {
            if (!filter_var($dest, FILTER_VALIDATE_EMAIL)) {
                $this->addError('destination_email', "Alamat email '{$dest}' tidak valid.");
                return;
            }
            $destList[] = $dest;
        }
        $destList = array_unique($destList);

        // Periksa apakah email sumber adalah akun mailbox fisik aktif
        $isLocalMailbox = VirtualUser::where('email', $fullSourceEmail)->exists();

        if ($this->keep_local_copy || $isLocalMailbox) {
            if (!in_array($fullSourceEmail, $destList)) {
                array_unshift($destList, $fullSourceEmail);
            }
        } else {
            // Cegah loop tunggal jika bukan keep local copy
            if (count($destList) === 1 && $destList[0] === $fullSourceEmail) {
                $this->addError('destination_email', 'Email tujuan tidak boleh hanya diarahkan ke diri sendiri tanpa tujuan lain.');
                return;
            }
        }

        $finalDestination = implode(', ', $destList);

        // Cek duplikasi record
        if (VirtualAlias::where('source_email', $fullSourceEmail)->where('destination_email', $finalDestination)->exists()) {
            $this->addError('destination_email', 'Pengalihan persis seperti ini sudah terdaftar.');
            return;
        }

        VirtualAlias::create([
            'domain_id' => $domain->id,
            'source_email' => $fullSourceEmail,
            'destination_email' => $finalDestination,
            'is_active' => $this->is_active,
        ]);

        $this->reset(['source_prefix', 'destination_email', 'keep_local_copy']);
        $this->is_active = true;
        session()->flash('message', "Alias {$fullSourceEmail} berhasil diarahkan ke {$finalDestination}!");
    }

    public function editAlias($aliasId)
    {
        $alias = VirtualAlias::findOrFail($aliasId);
        $this->editingAliasId = $alias->id;
        $parts = explode('@', $alias->source_email);
        $this->editSourcePrefix = $parts[0] ?? '';
        $this->editDomainId = $alias->domain_id;

        // Pisahkan email self jika ada
        $dests = array_map('trim', explode(',', $alias->destination_email));
        $this->editKeepLocalCopy = in_array(strtolower($alias->source_email), array_map('strtolower', $dests));

        $filteredDests = array_filter($dests, function($d) use ($alias) {
            return strtolower(trim($d)) !== strtolower(trim($alias->source_email));
        });

        $this->editDestinationEmail = implode(', ', $filteredDests) ?: $alias->destination_email;
        $this->editIsActive = (bool) $alias->is_active;
        $this->showEditModal = true;
    }

    public function closeEditModal()
    {
        $this->showEditModal = false;
        $this->editingAliasId = null;
        $this->reset(['editSourcePrefix', 'editDomainId', 'editDestinationEmail', 'editKeepLocalCopy', 'editIsActive']);
    }

    public function updateAlias()
    {
        $this->validate([
            'editSourcePrefix' => 'required|string|regex:/^[a-zA-Z0-9._-]+$/',
            'editDomainId' => 'required|exists:virtual_domains,id',
            'editDestinationEmail' => 'required|string',
        ], [
            'editSourcePrefix.regex' => 'Format alias depan tidak valid (contoh: info, support, billing).',
        ]);

        $alias = VirtualAlias::findOrFail($this->editingAliasId);
        $domain = VirtualDomain::findOrFail($this->editDomainId);
        $fullSourceEmail = strtolower(trim($this->editSourcePrefix)) . '@' . $domain->name;

        $rawDestList = array_filter(array_map('trim', explode(',', strtolower($this->editDestinationEmail))));
        if (empty($rawDestList)) {
            $this->addError('editDestinationEmail', 'Masukkan minimal satu alamat email tujuan yang valid.');
            return;
        }

        $destList = [];
        foreach ($rawDestList as $dest) {
            if (!filter_var($dest, FILTER_VALIDATE_EMAIL)) {
                $this->addError('editDestinationEmail', "Alamat email '{$dest}' tidak valid.");
                return;
            }
            $destList[] = $dest;
        }
        $destList = array_unique($destList);

        $isLocalMailbox = VirtualUser::where('email', $fullSourceEmail)->exists();

        if ($this->editKeepLocalCopy || $isLocalMailbox) {
            if (!in_array($fullSourceEmail, $destList)) {
                array_unshift($destList, $fullSourceEmail);
            }
        } else {
            if (count($destList) === 1 && $destList[0] === $fullSourceEmail) {
                $this->addError('editDestinationEmail', 'Email tujuan tidak boleh hanya diarahkan ke diri sendiri.');
                return;
            }
        }

        $finalDestination = implode(', ', $destList);

        $alias->update([
            'domain_id' => $domain->id,
            'source_email' => $fullSourceEmail,
            'destination_email' => $finalDestination,
            'is_active' => $this->editIsActive,
        ]);

        $this->closeEditModal();
        session()->flash('message', "Pengalihan alias {$fullSourceEmail} berhasil diperbarui!");
    }

    public function toggleStatus($aliasId)
    {
        $alias = VirtualAlias::findOrFail($aliasId);
        $alias->update(['is_active' => !$alias->is_active]);
    }

    public function deleteAlias($aliasId)
    {
        $alias = VirtualAlias::findOrFail($aliasId);
        $alias->delete();
        session()->flash('message', 'Alias berhasil dihapus.');
    }

    public function render()
    {
        $domains = VirtualDomain::where('is_active', true)->get();

        $aliases = VirtualAlias::with('domain')
            ->when($this->search, function ($query) {
                $query->where('source_email', 'like', '%' . $this->search . '%')
                      ->orWhere('destination_email', 'like', '%' . $this->search . '%');
            })
            ->latest()
            ->get();

        return view('components.admin.⚡alias-manager', [
            'domains' => $domains,
            'aliases' => $aliases,
        ])->layout('layouts.app', ['title' => 'Aliases & Forwarding - Mail Portal']);
    }
};
?>

<div class="space-y-6 max-w-7xl mx-auto">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-extrabold text-white tracking-tight flex items-center gap-2">
                <i data-lucide="forward" class="w-6 h-6 text-indigo-400"></i>
                Virtual Aliases & Forwarding
            </h2>
            <p class="text-sm text-slate-400 mt-1">
                Data disimpan di tabel <code class="text-indigo-300 font-mono text-xs">virtual_aliases</code> untuk pengalihan email otomatis oleh Postfix MTA (Mendukung Multi-Penerima & Salinan Lokal).
            </p>
        </div>
    </div>

    @if (session()->has('message'))
        <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-sm flex items-center gap-3">
            <i data-lucide="check-circle" class="w-5 h-5 shrink-0"></i>
            <span>{{ session('message') }}</span>
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Form Tambah Alias (1 Col) -->
        <div class="p-6 rounded-2xl bg-slate-900/60 border border-slate-800 backdrop-blur-md space-y-4">
            <h3 class="text-base font-bold text-white flex items-center gap-2 border-b border-slate-800 pb-3">
                <i data-lucide="plus-circle" class="w-4 h-4 text-indigo-400"></i>
                Buat Pengalihan (Alias)
            </h3>

            <form wire:submit="createAlias" class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Email Sumber (Inbound Address)</label>
                    <div class="flex flex-col sm:flex-row rounded-lg overflow-hidden border border-slate-700 focus-within:border-indigo-500 focus-within:ring-1 focus-within:ring-indigo-500">
                        <input type="text" wire:model="source_prefix" placeholder="info / support" 
                               class="w-full sm:w-1/2 px-3 py-2 bg-slate-950 text-sm text-white placeholder-slate-500 focus:outline-none border-b sm:border-b-0 sm:border-r border-slate-800 font-mono">
                        <div class="flex items-center w-full sm:w-1/2 bg-slate-900">
                            <span class="px-2.5 py-2 text-slate-400 text-xs font-mono select-none">@</span>
                            <select wire:model="domain_id" class="w-full px-2 py-2 bg-slate-900 text-xs text-white focus:outline-none font-mono">
                                @foreach($domains as $dom)
                                    <option value="{{ $dom->id }}">{{ $dom->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    @error('source_prefix') <span class="text-xs text-rose-400 mt-1 block">{{ $message }}</span> @enderror
                    @error('domain_id') <span class="text-xs text-rose-400 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Diteruskan Ke (Destination Email)</label>
                    <input type="text" wire:model="destination_email" placeholder="admin@perusahaan.net.id, team@gmail.com" 
                           class="w-full px-3.5 py-2 bg-slate-950 border border-slate-700 rounded-lg text-sm text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500 font-mono">
                    <p class="text-[10px] text-slate-500 mt-1">Bisa satu atau lebih email dipisah koma (internal mailbox maupun Gmail/eksternal).</p>
                    @error('destination_email') <span class="text-xs text-rose-400 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div class="p-3 rounded-xl bg-slate-950 border border-slate-800 space-y-2">
                    <div class="flex items-start gap-2.5">
                        <input type="checkbox" id="keep_local_copy" wire:model="keep_local_copy" class="mt-0.5 rounded bg-slate-900 border-slate-700 text-indigo-600 focus:ring-indigo-500">
                        <div>
                            <label for="keep_local_copy" class="text-xs font-medium text-slate-200 cursor-pointer block">
                                Simpan salinan di Mailbox Lokal
                            </label>
                            <p class="text-[10px] text-slate-400 mt-0.5">
                                Jika alamat sumber merupakan akun mailbox fisik, email akan tetap masuk ke Inbox lokal selain diteruskan.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-2 pt-1">
                    <input type="checkbox" id="alias_active" wire:model="is_active" class="rounded bg-slate-950 border-slate-700 text-indigo-600 focus:ring-indigo-500">
                    <label for="alias_active" class="text-xs text-slate-300 cursor-pointer">Pengalihan langsung aktif (Postfix routing)</label>
                </div>

                <button type="submit" class="w-full py-2.5 px-4 bg-indigo-600 hover:bg-indigo-500 text-white font-semibold text-xs rounded-lg transition-all shadow-md shadow-indigo-600/30 flex items-center justify-center gap-2 cursor-pointer">
                    <span wire:loading.remove wire:target="createAlias">Simpan Pengalihan</span>
                    <span wire:loading wire:target="createAlias">Menyimpan...</span>
                </button>
            </form>
        </div>

        <!-- Tabel Daftar Alias (2 Cols) -->
        <div class="lg:col-span-2 p-6 rounded-2xl bg-slate-900/60 border border-slate-800 backdrop-blur-md space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-800 pb-3">
                <h3 class="text-base font-bold text-white flex items-center gap-2">
                    <i data-lucide="list" class="w-4 h-4 text-indigo-400"></i>
                    Daftar Routing Pengalihan Email
                </h3>
                <div class="w-full sm:w-64">
                    <input type="text" wire:model.live.debounce.300ms="search" placeholder="Cari email..." 
                           class="w-full px-3 py-1.5 bg-slate-950 border border-slate-700 rounded-lg text-xs text-white placeholder-slate-500 focus:outline-none">
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="text-slate-400 border-b border-slate-800">
                            <th class="py-3 px-3 font-medium">Email Sumber</th>
                            <th class="py-3 px-3 font-medium"></th>
                            <th class="py-3 px-3 font-medium">Email Tujuan & Mode</th>
                            <th class="py-3 px-3 font-medium">Status</th>
                            <th class="py-3 px-3 font-medium text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60 text-slate-200">
                        @forelse($aliases as $alias)
                        @php
                            $dests = array_map('trim', explode(',', $alias->destination_email));
                            $hasLocal = in_array(strtolower($alias->source_email), array_map('strtolower', $dests));
                        @endphp
                        <tr class="hover:bg-slate-800/30 transition-colors">
                            <td class="py-3 px-3 font-semibold text-white">
                                <span class="text-indigo-300 font-mono font-bold">{{ $alias->source_email }}</span>
                            </td>
                            <td class="py-3 px-1 text-slate-500 text-center">
                                <i data-lucide="arrow-right" class="w-3.5 h-3.5 inline"></i>
                            </td>
                            <td class="py-3 px-3">
                                <div class="space-y-1">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        @foreach($dests as $d)
                                            <span class="px-2 py-0.5 rounded-md bg-slate-950 border border-slate-700 text-slate-300 font-mono text-[11px] {{ strtolower($d) === strtolower($alias->source_email) ? 'border-indigo-500/40 text-indigo-300 font-semibold' : '' }}">
                                                {{ $d }}
                                            </span>
                                        @endforeach
                                    </div>
                                    @if($hasLocal)
                                        <span class="inline-flex items-center gap-1 text-[10px] text-cyan-400 font-medium">
                                            <i data-lucide="inbox" class="w-3 h-3"></i> Simpan Salinan Inbox Lokal Aktif
                                        </span>
                                    @endif
                                </div>
                            </td>
                            <td class="py-3 px-3">
                                <button wire:click="toggleStatus({{ $alias->id }})" class="cursor-pointer">
                                    @if($alias->is_active)
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 hover:bg-emerald-500/20 transition-all">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span> Aktif
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-medium bg-rose-500/10 text-rose-400 border border-rose-500/20 hover:bg-rose-500/20 transition-all">
                                            Nonaktif
                                        </span>
                                    @endif
                                </button>
                            </td>
                            <td class="py-3 px-3 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <button wire:click="editAlias({{ $alias->id }})" title="Edit Pengalihan"
                                            class="p-1.5 text-slate-400 hover:text-indigo-400 hover:bg-indigo-500/10 rounded-lg transition-all cursor-pointer">
                                        <i data-lucide="edit-3" class="w-4 h-4"></i>
                                    </button>
                                    <button wire:click="deleteAlias({{ $alias->id }})" wire:confirm="Hapus pengalihan alias ini?" 
                                            title="Hapus Pengalihan"
                                            class="p-1.5 text-slate-400 hover:text-rose-400 hover:bg-rose-500/10 rounded-lg transition-all cursor-pointer">
                                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="5" class="py-8 text-center text-slate-400">
                                Belum ada data alias terdaftar.
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal Edit Alias & Forwarding -->
    @if($showEditModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm animate-fade-in">
        <div class="w-full max-w-lg p-6 rounded-2xl bg-slate-900 border border-slate-800 shadow-2xl space-y-4">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-lg bg-indigo-500/10 border border-indigo-500/20 flex items-center justify-center text-indigo-400">
                        <i data-lucide="edit-3" class="w-4 h-4"></i>
                    </div>
                    <div>
                        <h3 class="text-sm font-bold text-white">Edit Pengalihan Email</h3>
                        <p class="text-[11px] text-slate-400 font-mono">{{ $editSourcePrefix }}</p>
                    </div>
                </div>
                <button wire:click="closeEditModal" class="text-slate-400 hover:text-white transition-colors cursor-pointer">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>

            <form wire:submit="updateAlias" class="space-y-4 text-xs">
                <div>
                    <label class="block font-semibold text-slate-300 mb-1">Email Sumber (Inbound Address)</label>
                    <div class="flex flex-col sm:flex-row rounded-lg overflow-hidden border border-slate-700 focus-within:border-indigo-500 focus-within:ring-1 focus-within:ring-indigo-500">
                        <input type="text" wire:model="editSourcePrefix" placeholder="info / support" 
                               class="w-full sm:w-1/2 px-3 py-2 bg-slate-950 text-xs text-white placeholder-slate-500 focus:outline-none border-b sm:border-b-0 sm:border-r border-slate-800 font-mono">
                        <div class="flex items-center w-full sm:w-1/2 bg-slate-900">
                            <span class="px-2.5 py-2 text-slate-400 text-xs font-mono select-none">@</span>
                            <select wire:model="editDomainId" class="w-full px-2 py-2 bg-slate-900 text-xs text-white focus:outline-none font-mono">
                                @foreach($domains as $dom)
                                    <option value="{{ $dom->id }}">{{ $dom->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    @error('editSourcePrefix') <span class="text-rose-400 text-[10px] mt-1 block">{{ $message }}</span> @enderror
                    @error('editDomainId') <span class="text-rose-400 text-[10px] mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block font-semibold text-slate-300 mb-1">Diteruskan Ke (Destination Email)</label>
                    <input type="text" wire:model="editDestinationEmail" placeholder="admin@domain.com, email@gmail.com" 
                           class="w-full px-3.5 py-2 bg-slate-950 border border-slate-700 rounded-lg text-xs text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500 font-mono">
                    <p class="text-[10px] text-slate-500 mt-1">Bisa diarahkan ke satu atau beberapa email (pisahkan dengan koma).</p>
                    @error('editDestinationEmail') <span class="text-rose-400 text-[10px] mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div class="p-3 rounded-xl bg-slate-950 border border-slate-800 space-y-2">
                    <div class="flex items-start gap-2.5">
                        <input type="checkbox" id="edit_keep_local" wire:model="editKeepLocalCopy" class="mt-0.5 rounded bg-slate-900 border-slate-700 text-indigo-600 focus:ring-indigo-500">
                        <div>
                            <label for="edit_keep_local" class="text-xs font-medium text-slate-200 cursor-pointer block">
                                Simpan salinan di Mailbox Lokal
                            </label>
                            <p class="text-[10px] text-slate-400 mt-0.5">
                                Pastikan email tetap masuk ke Inbox lokal akun ini selain diteruskan ke alamat di atas.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-2 pt-1">
                    <input type="checkbox" id="edit_alias_active" wire:model="editIsActive" class="rounded bg-slate-950 border-slate-700 text-indigo-600 focus:ring-indigo-500">
                    <label for="edit_alias_active" class="text-xs text-slate-300 cursor-pointer">Pengalihan aktif (Postfix routing)</label>
                </div>

                <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-800">
                    <button type="button" wire:click="closeEditModal" 
                            class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-semibold transition-all cursor-pointer">
                        Batal
                    </button>
                    <button type="submit" 
                            class="px-5 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white font-bold text-xs transition-all shadow-md shadow-indigo-600/30 flex items-center gap-1.5 cursor-pointer">
                        <span wire:loading.remove wire:target="updateAlias">
                            <i data-lucide="check" class="w-4 h-4 inline mr-1"></i>Simpan Perubahan
                        </span>
                        <span wire:loading wire:target="updateAlias">Menyimpan...</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
    @endif
</div>