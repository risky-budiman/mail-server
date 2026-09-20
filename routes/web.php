<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;

// Root redirect cerdas
Route::get('/', function () {
    if (Auth::guard('web')->check()) {
        return redirect()->route('admin.dashboard');
    }
    if (Auth::guard('mailbox')->check()) {
        return redirect()->route('webmail.client');
    }
    return redirect()->route('webmail.login');
});

// ==========================================
// 1. PINTU MASUK (LOGIN & LOGOUT GUEST)
// ==========================================
Route::livewire('/admin/login', 'auth.admin-login')->name('admin.login');
Route::livewire('/webmail/login', 'auth.webmail-login')->name('webmail.login');

Route::post('/admin/logout', function () {
    Auth::guard('web')->logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();
    return redirect()->route('admin.login');
})->name('admin.logout');

Route::post('/webmail/logout', function () {
    Auth::guard('mailbox')->logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();
    return redirect()->route('webmail.login');
})->name('webmail.logout');

// ==========================================
// 2. PORTAL PENGATURAN MAIL SERVER (HANYA ADMIN)
// ==========================================
Route::prefix('admin')->middleware('auth.admin')->group(function () {
    Route::livewire('/dashboard', 'admin.dashboard')->name('admin.dashboard');
    Route::livewire('/domains', 'admin.domain-manager')->name('admin.domains');
    Route::livewire('/users', 'admin.user-manager')->name('admin.users');
    Route::livewire('/aliases', 'admin.alias-manager')->name('admin.aliases');
    Route::livewire('/dns-helper', 'admin.dns-helper')->name('admin.dns-helper');
    Route::livewire('/mail-tester', 'admin.mail-tester-simulator')->name('admin.mail-tester');
    Route::livewire('/installer', 'admin.installer-wizard')->name('admin.installer');
    Route::livewire('/profile', 'admin.admin-profile')->name('admin.profile');
    Route::livewire('/logs', 'admin.log-viewer')->name('admin.logs');
});

// ==========================================
// 3. APLIKASI WEBMAIL TERPISAH (HANYA USER MAILBOX)
// ==========================================
Route::middleware('auth.mailbox')->group(function () {
    Route::livewire('/webmail', 'webmail.mail-client')->name('webmail.client');
});
