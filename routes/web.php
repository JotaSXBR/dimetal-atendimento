<?php

use App\Livewire\Settings\WhatsApp;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

Route::middleware(['auth', 'can:admin'])->group(function () {
    Route::get('configuracoes/whatsapp', WhatsApp::class)->name('configuracoes.whatsapp');
});

require __DIR__.'/settings.php';
