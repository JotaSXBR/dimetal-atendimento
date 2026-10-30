<?php

use App\Http\Controllers\GoogleConnectionController;
use App\Http\Controllers\MessageMediaController;
use App\Http\Controllers\Webhooks\WahaWebhookController;
use App\Livewire\Audit\Index as AuditIndex;
use App\Livewire\Contacts\Index as ContactsIndex;
use App\Livewire\Inbox\Inbox;
use App\Livewire\Reports\Index as ReportsIndex;
use App\Livewire\Settings\BusinessHoursPage;
use App\Livewire\Settings\Channels;
use App\Livewire\Settings\Company;
use App\Livewire\Settings\GoogleContacts;
use App\Livewire\Settings\QuickReplies;
use App\Livewire\Settings\Retention;
use App\Livewire\Settings\Sectors;
use App\Livewire\Settings\Users;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::post('webhooks/waha', WahaWebhookController::class)->middleware('throttle:webhook')->name('webhooks.waha');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('inbox', Inbox::class)->name('inbox');
    Route::get('contatos', ContactsIndex::class)->name('contatos');
    Route::get('midia/{message}', MessageMediaController::class)->name('midia');
});

Route::middleware(['auth', 'can:supervisor'])->group(function () {
    Route::get('relatorios', ReportsIndex::class)->name('relatorios');
    Route::get('configuracoes/usuarios', Users::class)->name('configuracoes.usuarios');
    Route::get('configuracoes/respostas-rapidas', QuickReplies::class)->name('configuracoes.respostas');
});

Route::middleware(['auth', 'can:admin'])->group(function () {
    Route::get('auditoria', AuditIndex::class)->name('auditoria');
    Route::get('configuracoes/canais', Channels::class)->name('configuracoes.canais');
    Route::get('configuracoes/horario', BusinessHoursPage::class)->name('configuracoes.horario');
    Route::get('configuracoes/empresa', Company::class)->name('configuracoes.empresa');
    Route::get('configuracoes/google', GoogleContacts::class)->name('configuracoes.google');
    Route::get('configuracoes/google/redirect', [GoogleConnectionController::class, 'redirect'])->name('configuracoes.google.redirect');
    Route::get('configuracoes/google/callback', [GoogleConnectionController::class, 'callback'])->name('configuracoes.google.callback');
    Route::get('configuracoes/retencao', Retention::class)->name('configuracoes.retencao');
    Route::get('configuracoes/setores', Sectors::class)->name('configuracoes.setores');
});

require __DIR__.'/settings.php';
