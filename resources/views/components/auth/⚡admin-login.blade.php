<?php

use Livewire\Component;
use Illuminate\Support\Facades\Auth;

new class extends Component
{
    public $email = 'admin@mailportal.local';
    public $password = 'AdminSecret123!';
    public $remember = false;

    public function login()
    {
        $this->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if (Auth::guard('web')->attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            session()->regenerate();
            return $this->redirect(route('admin.dashboard'), navigate: true);
        }

        $this->addError('email', 'Kredensial login Admin Server tidak valid.');
    }

    public function render()
    {
        return view('components.auth.⚡admin-login')
            ->layout('layouts.auth', ['title' => 'Login Admin Mail Portal']);
    }
};
?>

<div class="w-full max-w-md p-8 rounded-3xl bg-slate-900/80 border border-slate-800 shadow-2xl backdrop-blur-xl">
    <div class="text-center mb-6">
        <div class="w-12 h-12 rounded-2xl bg-gradient-to-tr from-indigo-600 to-cyan-400 flex items-center justify-center mx-auto mb-3 shadow-lg shadow-indigo-500/20">
            <i data-lucide="shield" class="w-6 h-6 text-white"></i>
        </div>
        <h2 class="text-xl font-bold text-white tracking-tight">Admin Mail Portal</h2>
        <p class="text-xs text-slate-400 mt-1">Kelola Virtual Domains, Mailbox Users & Konfigurasi Postfix</p>
    </div>

    @if($errors->has('email'))
        <div class="mb-4 p-3 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-xs flex items-center gap-2">
            <i data-lucide="alert-circle" class="w-4 h-4 shrink-0"></i>
            <span>{{ $errors->first('email') }}</span>
        </div>
    @endif

    <form wire:submit="login" class="space-y-4">
        <div>
            <label class="block text-xs font-semibold text-slate-300 mb-1">Email Administrator</label>
            <input type="email" wire:model="email" 
                   class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-700 rounded-xl text-xs text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500">
        </div>

        <div>
            <label class="block text-xs font-semibold text-slate-300 mb-1">Password</label>
            <input type="password" wire:model="password" 
                   class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-700 rounded-xl text-xs text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500">
        </div>

        <div class="flex items-center justify-between text-xs pt-1">
            <label class="flex items-center gap-2 text-slate-400 cursor-pointer">
                <input type="checkbox" wire:model="remember" class="rounded bg-slate-950 border-slate-700 text-indigo-600 focus:ring-indigo-500">
                <span>Ingat sesi saya</span>
            </label>
            <a href="{{ route('webmail.login') }}" wire:navigate class="text-indigo-400 hover:text-indigo-300">
                Login ke Webmail &rarr;
            </a>
        </div>

        <button type="submit" class="w-full py-2.5 px-4 bg-indigo-600 hover:bg-indigo-500 text-white font-bold text-xs rounded-xl shadow-lg shadow-indigo-600/30 transition-all flex items-center justify-center gap-2">
            <span wire:loading.remove wire:target="login">Masuk Dashboard Admin</span>
            <span wire:loading wire:target="login">Memverifikasi...</span>
        </button>

        <div class="p-3 rounded-xl bg-slate-950/60 border border-slate-800 text-[11px] text-slate-400">
            <p class="font-bold text-slate-300 mb-0.5">Kredensial Admin Bawaan:</p>
            <p>Email: <span class="text-indigo-300 font-mono">admin@mailportal.local</span></p>
            <p>Password: <span class="text-indigo-300 font-mono">AdminSecret123!</span></p>
        </div>
    </form>
</div>