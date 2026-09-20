<?php

use Livewire\Component;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

new class extends Component
{
    public $name = '';
    public $email = '';
    public $currentPassword = '';
    public $newPassword = '';
    public $newPasswordConfirmation = '';

    public function mount()
    {
        $user = auth()->user();
        if ($user) {
            $this->name = $user->name;
            $this->email = $user->email;
        }
    }

    public function updateProfile()
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . auth()->id(),
        ]);

        $user = auth()->user();
        $user->name = $this->name;
        $user->email = $this->email;
        $user->save();

        session()->flash('profile_msg', 'Data profil Administrator berhasil diperbarui.');
    }

    public function updatePassword()
    {
        $this->validate([
            'currentPassword' => 'required|string',
            'newPassword' => ['required', 'string', 'min:8'],
            'newPasswordConfirmation' => 'required|same:newPassword',
        ], [
            'newPasswordConfirmation.same' => 'Konfirmasi password baru tidak cocok.',
            'newPassword.min' => 'Password baru minimal 8 karakter.',
        ]);

        $user = auth()->user();

        if (!Hash::check($this->currentPassword, $user->password)) {
            $this->addError('currentPassword', 'Password saat ini salah.');
            return;
        }

        $user->password = Hash::make($this->newPassword);
        $user->save();

        $this->reset(['currentPassword', 'newPassword', 'newPasswordConfirmation']);
        session()->flash('password_msg', 'Password Administrator berhasil diubah! Gunakan password baru untuk login berikutnya.');
    }

    public function render()
    {
        return view('components.admin.⚡admin-profile')
            ->layout('layouts.app', ['title' => 'Pengaturan Akun Admin - Mail Portal']);
    }
};
?>

<div class="space-y-6 max-w-5xl mx-auto">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-2xl font-extrabold text-white tracking-tight flex items-center gap-2.5">
                <i data-lucide="user-cog" class="w-6 h-6 text-indigo-400"></i>
                Pengaturan Akun & Keamanan Login Admin
            </h2>
            <p class="text-sm text-slate-400 mt-1">
                Ubah nama, email akses portal server, dan kata sandi akun Administrator Mail Server.
            </p>
        </div>
    </div>

    @if (session()->has('profile_msg'))
        <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 text-xs flex items-center gap-2">
            <i data-lucide="check-circle" class="w-4 h-4 text-emerald-400"></i>
            <span>{{ session('profile_msg') }}</span>
        </div>
    @endif

    @if (session()->has('password_msg'))
        <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 text-xs flex items-center gap-2">
            <i data-lucide="check-circle" class="w-4 h-4 text-emerald-400"></i>
            <span>{{ session('password_msg') }}</span>
        </div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <!-- Panel 1: Profil Admin -->
        <div class="p-6 rounded-2xl bg-slate-900/60 border border-slate-800 backdrop-blur-md space-y-4">
            <h3 class="text-sm font-bold text-white flex items-center gap-2 border-b border-slate-800 pb-3">
                <i data-lucide="user" class="w-4 h-4 text-indigo-400"></i>
                Informasi Identitas Admin
            </h3>

            <form wire:submit="updateProfile" class="space-y-4 text-xs">
                <div>
                    <label class="block font-semibold text-slate-300 mb-1">Nama Lengkap</label>
                    <input type="text" wire:model="name" 
                           class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-700 rounded-lg text-white focus:outline-none focus:border-indigo-500">
                    @error('name') <span class="text-rose-400 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block font-semibold text-slate-300 mb-1">Email Login Admin Portal</label>
                    <input type="email" wire:model="email" 
                           class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-700 rounded-lg text-white font-mono focus:outline-none focus:border-indigo-500">
                    @error('email') <span class="text-rose-400 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                    <p class="text-[10px] text-slate-500 mt-1">Email ini digunakan untuk masuk ke halaman <code class="text-indigo-300 font-mono">/admin/login</code>.</p>
                </div>

                <div class="pt-2">
                    <button type="submit" 
                            class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white font-bold text-xs rounded-xl transition-all shadow-md shadow-indigo-600/30 flex items-center gap-2">
                        <i data-lucide="save" class="w-4 h-4"></i>
                        <span wire:loading.remove wire:target="updateProfile">Simpan Profil</span>
                        <span wire:loading wire:target="updateProfile">Menyimpan...</span>
                    </button>
                </div>
            </form>
        </div>

        <!-- Panel 2: Ganti Password Admin -->
        <div class="p-6 rounded-2xl bg-slate-900/60 border border-slate-800 backdrop-blur-md space-y-4">
            <h3 class="text-sm font-bold text-white flex items-center gap-2 border-b border-slate-800 pb-3">
                <i data-lucide="key" class="w-4 h-4 text-cyan-400"></i>
                Ubah Kata Sandi (Password)
            </h3>

            <form wire:submit="updatePassword" class="space-y-4 text-xs">
                <div>
                    <label class="block font-semibold text-slate-300 mb-1">Password Saat Ini</label>
                    <input type="password" wire:model="currentPassword" placeholder="Masukkan password lama"
                           class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-700 rounded-lg text-white focus:outline-none focus:border-cyan-500">
                    @error('currentPassword') <span class="text-rose-400 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block font-semibold text-slate-300 mb-1">Password Baru</label>
                    <input type="password" wire:model="newPassword" placeholder="Minimal 8 karakter"
                           class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-700 rounded-lg text-white focus:outline-none focus:border-cyan-500">
                    @error('newPassword') <span class="text-rose-400 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block font-semibold text-slate-300 mb-1">Ulangi Password Baru</label>
                    <input type="password" wire:model="newPasswordConfirmation" placeholder="Ketik ulang password baru"
                           class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-700 rounded-lg text-white focus:outline-none focus:border-cyan-500">
                    @error('newPasswordConfirmation') <span class="text-rose-400 text-[11px] mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div class="pt-2">
                    <button type="submit" 
                            class="px-5 py-2.5 bg-gradient-to-r from-cyan-600 to-indigo-600 hover:from-cyan-500 hover:to-indigo-500 text-white font-bold text-xs rounded-xl transition-all shadow-md shadow-cyan-600/30 flex items-center gap-2">
                        <i data-lucide="shield-check" class="w-4 h-4"></i>
                        <span wire:loading.remove wire:target="updatePassword">Perbarui Password Admin</span>
                        <span wire:loading wire:target="updatePassword">Memperbarui...</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
