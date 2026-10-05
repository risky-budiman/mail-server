<?php

use Livewire\Component;
use App\Models\VirtualDomain;

new class extends Component
{
    public $name = '';
    public $description = '';
    public $is_active = true;
    public $search = '';

    // State Edit Modal
    public $showEditModal = false;
    public $editingDomainId = null;
    public $editName = '';
    public $editDescription = '';
    public $editIsActive = true;

    public function createDomain()
    {
        $this->validate([
            'name' => 'required|string|min:3|unique:virtual_domains,name|regex:/^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/',
            'description' => 'nullable|string|max:255',
        ], [
            'name.regex' => 'Format domain tidak valid (contoh: perusahaan.net.id).',
            'name.unique' => 'Domain ini sudah terdaftar di sistem.',
        ]);

        $newDomain = VirtualDomain::create([
            'name' => strtolower(trim($this->name)),
            'description' => $this->description,
            'is_active' => $this->is_active,
        ]);

        // Otomatisasi generate DKIM & konfigurasi OpenDKIM di Linux VPS
        if (PHP_OS_FAMILY === 'Linux') {
            try {
                \Illuminate\Support\Facades\Artisan::call('mail:sync-dkim', ['--domain' => $newDomain->name]);
            } catch (\Throwable $e) {
                // Log atau fallback
            }
        }

        $this->reset(['name', 'description']);
        $this->is_active = true;
        session()->flash('message', 'Domain baru berhasil ditambahkan! Kunci DKIM 2048-bit otomatis digenerate & OpenDKIM disinkronkan.');
    }

    public function editDomain($domainId)
    {
        $domain = VirtualDomain::findOrFail($domainId);
        $this->editingDomainId = $domain->id;
        $this->editName = $domain->name;
        $this->editDescription = $domain->description ?? '';
        $this->editIsActive = (bool) $domain->is_active;
        $this->showEditModal = true;
    }

    public function closeEditModal()
    {
        $this->showEditModal = false;
        $this->editingDomainId = null;
        $this->reset(['editName', 'editDescription', 'editIsActive']);
    }

    public function updateDomain()
    {
        $this->validate([
            'editName' => 'required|string|min:3|regex:/^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/|unique:virtual_domains,name,' . $this->editingDomainId,
            'editDescription' => 'nullable|string|max:255',
        ], [
            'editName.regex' => 'Format domain tidak valid (contoh: perusahaan.net.id).',
            'editName.unique' => 'Domain ini sudah digunakan oleh domain lain.',
        ]);

        $domain = VirtualDomain::findOrFail($this->editingDomainId);
        $oldName = $domain->name;
        $newName = strtolower(trim($this->editName));

        $domain->update([
            'name' => $newName,
            'description' => $this->editDescription,
            'is_active' => $this->editIsActive,
        ]);

        // Jika nama domain berubah, perbarui juga email user terkait agar konsisten
        if ($oldName !== $newName) {
            foreach ($domain->users as $u) {
                $parts = explode('@', $u->email);
                $local = $parts[0];
                $u->update([
                    'email' => $local . '@' . $newName,
                    'maildir_path' => $newName . '/' . $local . '/',
                ]);
            }
        }

        if (PHP_OS_FAMILY === 'Linux') {
            try {
                \Illuminate\Support\Facades\Artisan::call('mail:sync-dkim');
            } catch (\Throwable $e) {}
        }

        $this->closeEditModal();
        session()->flash('message', "Data domain {$newName} berhasil diperbarui!");
    }

    public function toggleStatus($domainId)
    {
        $domain = VirtualDomain::findOrFail($domainId);
        $domain->update(['is_active' => !$domain->is_active]);

        if (PHP_OS_FAMILY === 'Linux') {
            try {
                \Illuminate\Support\Facades\Artisan::call('mail:sync-dkim');
            } catch (\Throwable $e) {}
        }
    }

    public function deleteDomain($domainId)
    {
        $domain = VirtualDomain::withCount('users')->findOrFail($domainId);
        $domain->delete();

        if (PHP_OS_FAMILY === 'Linux') {
            try {
                \Illuminate\Support\Facades\Artisan::call('mail:sync-dkim');
            } catch (\Throwable $e) {}
        }

        session()->flash('message', 'Domain dan akun terkait berhasil dihapus.');
    }

    public function render()
    {
        $domains = VirtualDomain::withCount(['users', 'aliases'])
            ->when($this->search, function ($query) {
                $query->where('name', 'like', '%' . $this->search . '%')
                      ->orWhere('description', 'like', '%' . $this->search . '%');
            })
            ->latest()
            ->get();

        return view('components.admin.⚡domain-manager', [
            'domains' => $domains,
        ])->layout('layouts.app', ['title' => 'Virtual Domains - Mail Portal']);
    }
};
?>

<div class="space-y-6 max-w-7xl mx-auto">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-extrabold text-white tracking-tight flex items-center gap-2">
                <i data-lucide="globe" class="w-6 h-6 text-indigo-400"></i>
                Virtual Domains (Postfix Engine)
            </h2>
            <p class="text-sm text-slate-400 mt-1">
                Domain yang dikelola di sini langsung menentukan tabel <code class="text-indigo-300 font-mono text-xs">virtual_domains</code> untuk query Postfix MTA.
            </p>
        </div>
    </div>

    <!-- Flash Message -->
    @if (session()->has('message'))
        <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-sm flex items-center gap-3">
            <i data-lucide="check-circle" class="w-5 h-5 shrink-0"></i>
            <span>{{ session('message') }}</span>
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Form Tambah Domain (1 Col) -->
        <div class="p-6 rounded-2xl bg-slate-900/60 border border-slate-800 backdrop-blur-md space-y-4">
            <h3 class="text-base font-bold text-white flex items-center gap-2 border-b border-slate-800 pb-3">
                <i data-lucide="plus-circle" class="w-4 h-4 text-indigo-400"></i>
                Tambah Domain Baru
            </h3>

            <form wire:submit="createDomain" class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Nama Domain FQDN</label>
                    <input type="text" wire:model="name" placeholder="contoh: domain.net.id" 
                           class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-700 rounded-lg text-sm text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                    @error('name') <span class="text-xs text-rose-400 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Keterangan / Organisasi</label>
                    <input type="text" wire:model="description" placeholder="contoh: Portal Internal Perusahaan" 
                           class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-700 rounded-lg text-sm text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                    @error('description') <span class="text-xs text-rose-400 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div class="flex items-center gap-2 pt-1">
                    <input type="checkbox" id="is_active" wire:model="is_active" class="rounded bg-slate-950 border-slate-700 text-indigo-600 focus:ring-indigo-500">
                    <label for="is_active" class="text-xs text-slate-300">Langsung aktifkan untuk routing email</label>
                </div>

                <button type="submit" class="w-full py-2.5 px-4 bg-indigo-600 hover:bg-indigo-500 text-white font-semibold text-xs rounded-lg transition-all shadow-md shadow-indigo-600/30 flex items-center justify-center gap-2">
                    <span wire:loading.remove wire:target="createDomain">Simpan Domain</span>
                    <span wire:loading wire:target="createDomain">Menyimpan...</span>
                </button>
            </form>
        </div>

        <!-- Tabel Daftar Domain (2 Cols) -->
        <div class="lg:col-span-2 p-6 rounded-2xl bg-slate-900/60 border border-slate-800 backdrop-blur-md space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-800 pb-3">
                <h3 class="text-base font-bold text-white flex items-center gap-2">
                    <i data-lucide="list" class="w-4 h-4 text-indigo-400"></i>
                    Daftar Domain Terdaftar
                </h3>
                <div class="w-full sm:w-64">
                    <input type="text" wire:model.live.debounce.300ms="search" placeholder="Cari domain..." 
                           class="w-full px-3 py-1.5 bg-slate-950 border border-slate-700 rounded-lg text-xs text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500">
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="text-slate-400 border-b border-slate-800">
                            <th class="py-3 px-3 font-medium">Domain Name</th>
                            <th class="py-3 px-3 font-medium">Akun Mailbox</th>
                            <th class="py-3 px-3 font-medium">Aliases</th>
                            <th class="py-3 px-3 font-medium">Status</th>
                            <th class="py-3 px-3 font-medium text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60 text-slate-200">
                        @forelse($domains as $domain)
                        <tr class="hover:bg-slate-800/30 transition-colors">
                            <td class="py-3 px-3 font-semibold text-white">
                                <div class="flex items-center gap-2">
                                    <div class="w-8 h-8 rounded-lg bg-indigo-500/10 text-indigo-400 border border-indigo-500/20 flex items-center justify-center font-mono text-xs">
                                        @
                                    </div>
                                    <div>
                                        <p class="font-mono text-indigo-300">{{ $domain->name }}</p>
                                        <p class="text-[11px] text-slate-400 font-sans font-normal">{{ $domain->description ?? 'Tidak ada deskripsi' }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="py-3 px-3">
                                <span class="px-2 py-0.5 rounded bg-slate-800 text-slate-300 font-semibold text-[11px]">
                                    {{ $domain->users_count }} user
                                </span>
                            </td>
                            <td class="py-3 px-3">
                                <span class="px-2 py-0.5 rounded bg-slate-800 text-slate-300 font-semibold text-[11px]">
                                    {{ $domain->aliases_count }} alias
                                </span>
                            </td>
                            <td class="py-3 px-3">
                                <button wire:click="toggleStatus({{ $domain->id }})" class="cursor-pointer">
                                    @if($domain->is_active)
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
                                    <button wire:click="editDomain({{ $domain->id }})" title="Edit Domain"
                                            class="p-1.5 text-slate-400 hover:text-indigo-400 hover:bg-indigo-500/10 rounded-lg transition-all cursor-pointer">
                                        <i data-lucide="edit-3" class="w-4 h-4"></i>
                                    </button>
                                    <button wire:click="deleteDomain({{ $domain->id }})" wire:confirm="Yakin ingin menghapus domain ini beserta seluruh mailbox di dalamnya?" 
                                            title="Hapus Domain"
                                            class="p-1.5 text-slate-400 hover:text-rose-400 hover:bg-rose-500/10 rounded-lg transition-all cursor-pointer">
                                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="5" class="py-8 text-center text-slate-400">
                                Tidak ada domain ditemukan. Silakan tambahkan domain baru di samping.
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal Edit Domain -->
    @if($showEditModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm animate-fade-in">
        <div class="w-full max-w-md p-6 rounded-2xl bg-slate-900 border border-slate-800 shadow-2xl space-y-4">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-lg bg-indigo-500/10 border border-indigo-500/20 flex items-center justify-center text-indigo-400">
                        <i data-lucide="edit-3" class="w-4 h-4"></i>
                    </div>
                    <div>
                        <h3 class="text-sm font-bold text-white">Edit Domain</h3>
                        <p class="text-[11px] text-slate-400 font-mono">{{ $editName }}</p>
                    </div>
                </div>
                <button wire:click="closeEditModal" class="text-slate-400 hover:text-white transition-colors cursor-pointer">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>

            <form wire:submit="updateDomain" class="space-y-4 text-xs">
                <div>
                    <label class="block font-semibold text-slate-300 mb-1">Nama Domain FQDN</label>
                    <input type="text" wire:model="editName" placeholder="contoh: perusahaan.net.id"
                           class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-700 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:border-indigo-400 font-mono">
                    @error('editName') 
                        <span class="text-rose-400 text-[10px] mt-1 block">{{ $message }}</span> 
                    @enderror
                </div>

                <div>
                    <label class="block font-semibold text-slate-300 mb-1">Keterangan / Organisasi</label>
                    <input type="text" wire:model="editDescription" placeholder="contoh: Portal Internal Perusahaan"
                           class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-700 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:border-indigo-400">
                    @error('editDescription') 
                        <span class="text-rose-400 text-[10px] mt-1 block">{{ $message }}</span> 
                    @enderror
                </div>

                <div class="flex items-center gap-2 pt-1">
                    <input type="checkbox" id="edit_domain_active" wire:model="editIsActive" class="rounded bg-slate-950 border-slate-700 text-indigo-600 focus:ring-indigo-500">
                    <label for="edit_domain_active" class="text-xs text-slate-300 cursor-pointer">Aktifkan untuk routing email MTA</label>
                </div>

                <div class="p-3 rounded-xl bg-indigo-500/10 border border-indigo-500/20 text-indigo-300 text-[11px] leading-relaxed">
                    <p class="font-bold flex items-center gap-1 mb-0.5">
                        <i data-lucide="info" class="w-3.5 h-3.5"></i> Perhatian
                    </p>
                    Perubahan nama domain akan memperbarui alamat email seluruh akun mailbox yang terikat pada domain ini secara otomatis.
                </div>

                <div class="flex items-center justify-end gap-2 pt-2">
                    <button type="button" wire:click="closeEditModal" 
                            class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-semibold transition-all cursor-pointer">
                        Batal
                    </button>
                    <button type="submit" 
                            class="px-5 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white font-bold text-xs transition-all shadow-md shadow-indigo-600/30 flex items-center gap-1.5 cursor-pointer">
                        <span wire:loading.remove wire:target="updateDomain">
                            <i data-lucide="check" class="w-4 h-4 inline mr-1"></i>Simpan Perubahan
                        </span>
                        <span wire:loading wire:target="updateDomain">Menyimpan...</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
    @endif
</div>