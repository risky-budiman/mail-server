<?php

use Livewire\Component;
use Illuminate\Support\Facades\Auth;
use App\Models\VirtualUser;

new class extends Component
{
    public $email = 'admin@perusahaan.net.id';
    public $password = 'Secret123!';
    public $remember = false;

    public function login()
    {
        $this->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        // Autentikasi langsung ke tabel virtual_users (Akun Dovecot IMAP/Postfix)
        if (Auth::guard('mailbox')->attempt(['email' => $this->email, 'password' => $this->password, 'is_active' => true], $this->remember)) {
            session()->regenerate();
            
            // Catat last_login
            $user = Auth::guard('mailbox')->user();
            if ($user instanceof VirtualUser) {
                $user->update(['last_login_at' => now()]);
            }

            return $this->redirect(route('webmail.client'), navigate: true);
        }

        $this->addError('email', 'Email mailbox atau password salah, atau akun dinonaktifkan.');
    }

    public function render()
    {
        return view('components.auth.⚡webmail-login')
            ->layout('layouts.auth', ['title' => 'Login Webmail Client']);
    }
};
?>

<div class="w-full max-w-md p-8 rounded-3xl bg-slate-900/80 border border-slate-800 shadow-2xl backdrop-blur-xl">
    <div class="text-center mb-6">
        <div class="w-12 h-12 rounded-2xl bg-gradient-to-tr from-cyan-500 to-indigo-600 flex items-center justify-center mx-auto mb-3 shadow-lg shadow-cyan-500/20">
            <i data-lucide="inbox" class="w-6 h-6 text-white"></i>
        </div>
        <h2 class="text-xl font-bold text-white tracking-tight">Login Webmail Client</h2>
        <p class="text-xs text-slate-400 mt-1">Masuk dengan alamat email domain bisnis Anda (Dovecot IMAP)</p>
    </div>

    @if($errors->has('email'))
        <div class="mb-4 p-3 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-xs flex items-center gap-2">
            <i data-lucide="alert-circle" class="w-4 h-4 shrink-0"></i>
            <span>{{ $errors->first('email') }}</span>
        </div>
    @endif

    <form wire:submit="login" class="space-y-4">
        <div>
            <label class="block text-xs font-semibold text-slate-300 mb-1">Alamat Email Mailbox</label>
            <input type="email" wire:model="email" placeholder="contoh: nama@perusahaan.net.id" 
                   class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-700 rounded-xl text-xs text-white placeholder-slate-500 focus:outline-none focus:border-cyan-500 font-mono">
        </div>

        <div>
            <label class="block text-xs font-semibold text-slate-300 mb-1">Password Mailbox</label>
            <input type="password" wire:model="password" 
                   class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-700 rounded-xl text-xs text-white placeholder-slate-500 focus:outline-none focus:border-cyan-500">
        </div>

        <div class="flex items-center justify-between text-xs pt-1">
            <label class="flex items-center gap-2 text-slate-400 cursor-pointer">
                <input type="checkbox" wire:model="remember" class="rounded bg-slate-950 border-slate-700 text-cyan-600 focus:ring-cyan-500">
                <span>Ingat sesi webmail</span>
            </label>
            <a href="{{ route('admin.login') }}" wire:navigate class="text-slate-400 hover:text-white">
                &larr; Ke Portal Admin
            </a>
        </div>

        <button type="submit" class="w-full py-2.5 px-4 bg-gradient-to-r from-cyan-600 to-indigo-600 hover:from-cyan-500 hover:to-indigo-500 text-white font-bold text-xs rounded-xl shadow-lg shadow-cyan-600/30 transition-all flex items-center justify-center gap-2">
            <span wire:loading.remove wire:target="login">Masuk ke Webmail</span>
            <span wire:loading wire:target="login">Menghubungkan ke Dovecot...</span>
        </button>

        <div class="p-3 rounded-xl bg-slate-950/60 border border-slate-800 text-[11px] text-slate-400">
            <p class="font-bold text-slate-300 mb-0.5">Akun Contoh Pengujian:</p>
            <p>Email: <span class="text-cyan-300 font-mono">admin@perusahaan.net.id</span></p>
            <p>Password: <span class="text-cyan-300 font-mono">Secret123!</span></p>
        </div>
    </form>
</div>