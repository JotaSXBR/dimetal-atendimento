<?php

use App\Http\Controllers\Webhooks\WahaWebhookController;
use App\Livewire\Inbox\Inbox;
use App\Livewire\Settings\Channels;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::post('webhooks/waha', WahaWebhookController::class)->middleware('throttle:webhook')->name('webhooks.waha');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('inbox', Inbox::class)->name('inbox');
});

Route::middleware(['auth', 'can:admin'])->group(function () {
    Route::get('configuracoes/canais', Channels::class)->name('configuracoes.canais');
});

require __DIR__.'/settings.php';
