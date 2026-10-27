<?php

use App\Http\Controllers\Webhooks\WahaWebhookController;
use App\Livewire\Settings\Channels;
use App\Livewire\Settings\WhatsApp;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::post('webhooks/waha', WahaWebhookController::class)->name('webhooks.waha');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

Route::middleware(['auth', 'can:admin'])->group(function () {
    Route::get('configuracoes/whatsapp', WhatsApp::class)->name('configuracoes.whatsapp');
    Route::get('configuracoes/canais', Channels::class)->name('configuracoes.canais');
});

require __DIR__.'/settings.php';
