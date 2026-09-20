<?php

use Livewire\Component;
use App\Models\VirtualAlias;
use App\Models\VirtualDomain;

new class extends Component
{
    public $domain_id = '';
    public $source_prefix = '';
    public $destination_email = '';
    public $is_active = true;
    public $search = '';

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
            'destination_email' => 'required|email',
        ], [
            'source_prefix.regex' => 'Format alias depan tidak valid (contoh: info, support, billing).',
            'destination_email.email' => 'Format email tujuan pengalihan harus valid.',
        ]);

        $domain = VirtualDomain::findOrFail($this->domain_id);
        $fullSourceEmail = strtolower(trim($this->source_prefix)) . '@' . $domain->name;

        // Cek duplikasi alias yang sama
        if (VirtualAlias::where('source_email', $fullSourceEmail)->where('destination_email', $this->destination_email)->exists()) {
            $this->addError('destination_email', 'Alias ini sudah dialihkan ke email tujuan tersebut.');
            return;
        }

        VirtualAlias::create([
            'domain_id' => $domain->id,
            'source_email' => $fullSourceEmail,
            'destination_email' => strtolower(trim($this->destination_email)),
            'is_active' => $this->is_active,
        ]);

        $this->reset(['source_prefix', 'destination_email']);
        $this->is_active = true;
        session()->flash('message', "Alias {$fullSourceEmail} berhasil diarahkan ke {$this->destination_email}!");
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
                Data disimpan di tabel <code class="text-indigo-300 font-mono text-xs">virtual_aliases</code> untuk pengalihan email otomatis oleh Postfix MTA.
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
                               class="w-full sm:w-1/2 px-3 py-2 bg-slate-950 text-sm text-white placeholder-slate-500 focus:outline-none border-b sm:border-b-0 sm:border-r border-slate-800">
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
                    <input type="email" wire:model="destination_email" placeholder="admin@perusahaan.net.id atau email@gmail.com" 
                           class="w-full px-3.5 py-2 bg-slate-950 border border-slate-700 rounded-lg text-sm text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500">
                    <p class="text-[10px] text-slate-500 mt-1">Bisa diarahkan ke akun mailbox lokal atau alamat email luar (Gmail, dll)</p>
                    @error('destination_email') <span class="text-xs text-rose-400 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div class="flex items-center gap-2 pt-1">
                    <input type="checkbox" id="alias_active" wire:model="is_active" class="rounded bg-slate-950 border-slate-700 text-indigo-600 focus:ring-indigo-500">
                    <label for="alias_active" class="text-xs text-slate-300">Pengalihan langsung aktif</label>
                </div>

                <button type="submit" class="w-full py-2.5 px-4 bg-indigo-600 hover:bg-indigo-500 text-white font-semibold text-xs rounded-lg transition-all shadow-md shadow-indigo-600/30 flex items-center justify-center gap-2">
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
                            <th class="py-3 px-3 font-medium">Email Tujuan</th>
                            <th class="py-3 px-3 font-medium">Status</th>
                            <th class="py-3 px-3 font-medium text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60 text-slate-200">
                        @forelse($aliases as $alias)
                        <tr class="hover:bg-slate-800/30 transition-colors">
                            <td class="py-3 px-3 font-semibold text-white">
                                <span class="text-indigo-300 font-mono">{{ $alias->source_email }}</span>
                            </td>
                            <td class="py-3 px-1 text-slate-500 text-center">
                                <i data-lucide="arrow-right" class="w-3.5 h-3.5 inline"></i>
                            </td>
                            <td class="py-3 px-3 text-slate-300 font-mono">
                                {{ $alias->destination_email }}
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
                                <button wire:click="deleteAlias({{ $alias->id }})" wire:confirm="Hapus pengalihan alias ini?" 
                                        class="p-1.5 text-slate-400 hover:text-rose-400 hover:bg-rose-500/10 rounded-lg transition-all">
                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                </button>
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
</div>