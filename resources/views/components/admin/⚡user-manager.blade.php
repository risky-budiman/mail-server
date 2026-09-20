<?php

use Livewire\Component;
use App\Models\VirtualUser;
use App\Models\VirtualDomain;
use Illuminate\Support\Facades\Hash;

new class extends Component
{
    public $username = '';
    public $domain_id = '';
    public $name = '';
    public $password = '';
    public $quota_gb = 2; // Default 2 GB
    public $is_active = true;
    public $search = '';
    public $filter_domain = '';

    public function mount()
    {
        $firstDomain = VirtualDomain::where('is_active', true)->first();
        if ($firstDomain) {
            $this->domain_id = $firstDomain->id;
        }
    }

    public function createUser()
    {
        $this->validate([
            'username' => 'required|string|regex:/^[a-zA-Z0-9._-]+$/',
            'domain_id' => 'required|exists:virtual_domains,id',
            'name' => 'nullable|string|max:100',
            'password' => 'required|string|min:6',
            'quota_gb' => 'required|numeric|min:0.5|max:100',
        ], [
            'username.regex' => 'Username hanya boleh huruf, angka, titik, atau strip.',
        ]);

        $domain = VirtualDomain::findOrFail($this->domain_id);
        $fullEmail = strtolower(trim($this->username)) . '@' . $domain->name;

        // Cek duplikasi email
        if (VirtualUser::where('email', $fullEmail)->exists()) {
            $this->addError('username', 'Alamat email ' . $fullEmail . ' sudah terdaftar.');
            return;
        }

        $quotaBytes = (int) ($this->quota_gb * 1024 * 1024 * 1024);
        $maildir = $domain->name . '/' . strtolower(trim($this->username)) . '/';

        // Bcrypt kompatibel langsung dengan Dovecot BLF-CRYPT/BCRYPT
        $passwordHash = Hash::make($this->password);

        VirtualUser::create([
            'domain_id' => $domain->id,
            'email' => $fullEmail,
            'name' => $this->name ?: ucfirst(trim($this->username)),
            'password' => $passwordHash,
            'quota_bytes' => $quotaBytes,
            'used_bytes' => 0,
            'maildir_path' => $maildir,
            'is_active' => $this->is_active,
        ]);

        $this->reset(['username', 'name', 'password']);
        $this->quota_gb = 2;
        $this->is_active = true;
        session()->flash('message', "Akun mailbox {$fullEmail} berhasil dibuat dan aktif di database!");
    }

    public function toggleStatus($userId)
    {
        $user = VirtualUser::findOrFail($userId);
        $user->update(['is_active' => !$user->is_active]);
    }

    public function deleteUser($userId)
    {
        $user = VirtualUser::findOrFail($userId);
        $user->delete();
        session()->flash('message', 'Akun mailbox berhasil dihapus.');
    }

    public function render()
    {
        $domains = VirtualDomain::where('is_active', true)->get();

        $users = VirtualUser::with('domain')
            ->when($this->search, function ($query) {
                $query->where('email', 'like', '%' . $this->search . '%')
                      ->orWhere('name', 'like', '%' . $this->search . '%');
            })
            ->when($this->filter_domain, function ($query) {
                $query->where('domain_id', $this->filter_domain);
            })
            ->latest()
            ->get();

        return view('components.admin.⚡user-manager', [
            'domains' => $domains,
            'users' => $users,
        ])->layout('layouts.app', ['title' => 'Mailboxes & Users - Mail Portal']);
    }
};
?>

<div class="space-y-6 max-w-7xl mx-auto">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-extrabold text-white tracking-tight flex items-center gap-2">
                <i data-lucide="users" class="w-6 h-6 text-indigo-400"></i>
                Mailboxes & Virtual Users
            </h2>
            <p class="text-sm text-slate-400 mt-1">
                Data di sini tersimpan pada tabel <code class="text-indigo-300 font-mono text-xs">virtual_users</code> yang digunakan Dovecot IMAP/POP3 dan Postfix SASL Authentication.
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
        <!-- Form Tambah User Mailbox (1 Col) -->
        <div class="p-6 rounded-2xl bg-slate-900/60 border border-slate-800 backdrop-blur-md space-y-4">
            <h3 class="text-base font-bold text-white flex items-center gap-2 border-b border-slate-800 pb-3">
                <i data-lucide="user-plus" class="w-4 h-4 text-indigo-400"></i>
                Buat Mailbox Baru
            </h3>

            <form wire:submit="createUser" class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Username & Domain</label>
                    <div class="flex flex-col sm:flex-row rounded-lg overflow-hidden border border-slate-700 focus-within:border-indigo-500 focus-within:ring-1 focus-within:ring-indigo-500">
                        <input type="text" wire:model="username" placeholder="budi" 
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
                    @error('username') <span class="text-xs text-rose-400 mt-1 block">{{ $message }}</span> @enderror
                    @error('domain_id') <span class="text-xs text-rose-400 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Nama Tampilan (Display Name)</label>
                    <input type="text" wire:model="name" placeholder="contoh: Budi Santoso" 
                           class="w-full px-3.5 py-2 bg-slate-950 border border-slate-700 rounded-lg text-sm text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500">
                    @error('name') <span class="text-xs text-rose-400 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Password Akun Email</label>
                    <input type="password" wire:model="password" placeholder="Minimal 6 karakter" 
                           class="w-full px-3.5 py-2 bg-slate-950 border border-slate-700 rounded-lg text-sm text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500">
                    <p class="text-[10px] text-slate-500 mt-1">Dihash menggunakan algoritma bcrypt (Dovecot BLF-CRYPT compatible)</p>
                    @error('password') <span class="text-xs text-rose-400 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Alokasi Kuota Storage (GB)</label>
                    <input type="number" step="0.5" wire:model="quota_gb" 
                           class="w-full px-3.5 py-2 bg-slate-950 border border-slate-700 rounded-lg text-sm text-white focus:outline-none focus:border-indigo-500">
                    @error('quota_gb') <span class="text-xs text-rose-400 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div class="flex items-center gap-2 pt-1">
                    <input type="checkbox" id="user_active" wire:model="is_active" class="rounded bg-slate-950 border-slate-700 text-indigo-600 focus:ring-indigo-500">
                    <label for="user_active" class="text-xs text-slate-300">Mailbox langsung aktif</label>
                </div>

                <button type="submit" class="w-full py-2.5 px-4 bg-indigo-600 hover:bg-indigo-500 text-white font-semibold text-xs rounded-lg transition-all shadow-md shadow-indigo-600/30 flex items-center justify-center gap-2">
                    <span wire:loading.remove wire:target="createUser">Buat Akun Mailbox</span>
                    <span wire:loading wire:target="createUser">Memproses...</span>
                </button>
            </form>
        </div>

        <!-- Tabel Daftar Mailbox (2 Cols) -->
        <div class="lg:col-span-2 p-6 rounded-2xl bg-slate-900/60 border border-slate-800 backdrop-blur-md space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-800 pb-3">
                <h3 class="text-base font-bold text-white flex items-center gap-2">
                    <i data-lucide="list-filter" class="w-4 h-4 text-indigo-400"></i>
                    Daftar Akun Mailbox
                </h3>
                <div class="flex items-center gap-2">
                    <select wire:model.live="filter_domain" class="px-2.5 py-1.5 bg-slate-950 border border-slate-700 rounded-lg text-xs text-white focus:outline-none">
                        <option value="">Semua Domain</option>
                        @foreach($domains as $dom)
                            <option value="{{ $dom->id }}">{{ $dom->name }}</option>
                        @endforeach
                    </select>
                    <input type="text" wire:model.live.debounce.300ms="search" placeholder="Cari email / nama..." 
                           class="px-3 py-1.5 bg-slate-950 border border-slate-700 rounded-lg text-xs text-white placeholder-slate-500 focus:outline-none">
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="text-slate-400 border-b border-slate-800">
                            <th class="py-3 px-3 font-medium">Akun Email</th>
                            <th class="py-3 px-3 font-medium">Lokasi Maildir</th>
                            <th class="py-3 px-3 font-medium">Penggunaan Kuota</th>
                            <th class="py-3 px-3 font-medium">Status</th>
                            <th class="py-3 px-3 font-medium text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60 text-slate-200">
                        @forelse($users as $user)
                        <tr class="hover:bg-slate-800/30 transition-colors">
                            <td class="py-3 px-3">
                                <div class="font-semibold text-white">{{ $user->email }}</div>
                                <div class="text-[11px] text-slate-400">{{ $user->name }}</div>
                            </td>
                            <td class="py-3 px-3">
                                <code class="px-2 py-0.5 rounded bg-slate-950 text-indigo-300 font-mono text-[10px] border border-slate-800">
                                    /var/vmail/{{ $user->maildir_path }}
                                </code>
                            </td>
                            <td class="py-3 px-3">
                                <div class="w-32">
                                    <div class="flex justify-between text-[10px] mb-1 text-slate-400">
                                        <span>{{ round($user->used_bytes / (1024*1024), 0) }}MB</span>
                                        <span>{{ $user->formatted_quota }}</span>
                                    </div>
                                    <div class="w-full h-1.5 bg-slate-800 rounded-full overflow-hidden">
                                        <div class="h-full {{ $user->quota_usage_percent > 80 ? 'bg-rose-500' : 'bg-indigo-500' }}" style="width: {{ $user->quota_usage_percent }}%"></div>
                                    </div>
                                </div>
                            </td>
                            <td class="py-3 px-3">
                                <button wire:click="toggleStatus({{ $user->id }})" class="cursor-pointer">
                                    @if($user->is_active)
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
                                <button wire:click="deleteUser({{ $user->id }})" wire:confirm="Hapus akun mailbox ini? Seluruh data email akan terhapus." 
                                        class="p-1.5 text-slate-400 hover:text-rose-400 hover:bg-rose-500/10 rounded-lg transition-all">
                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                </button>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="5" class="py-8 text-center text-slate-400">
                                Belum ada user mailbox. Silakan buat akun baru di formulir sebelah kiri.
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>