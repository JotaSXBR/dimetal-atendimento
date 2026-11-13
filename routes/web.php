<?php

use App\Http\Controllers\AvatarController;
use App\Http\Controllers\BackupDownloadController;
use App\Http\Controllers\GoogleConnectionController;
use App\Http\Controllers\LogoController;
use App\Http\Controllers\MessageMediaController;
use App\Http\Controllers\RestorePageController;
use App\Http\Controllers\RestoreStatusController;
use App\Http\Controllers\Webhooks\WahaWebhookController;
use App\Livewire\Audit\Index as AuditIndex;
use App\Livewire\Audit\SystemLog as AuditSystemLog;
use App\Livewire\Automation\Editor as AutomationEditor;
use App\Livewire\Automation\Index as AutomationIndex;
use App\Livewire\Contacts\Index as ContactsIndex;
use App\Livewire\History\Index as HistoryIndex;
use App\Livewire\Inbox\Inbox;
use App\Livewire\Reports\Index as ReportsIndex;
use App\Livewire\Settings\Backups;
use App\Livewire\Settings\BusinessHoursPage;
use App\Livewire\Settings\Channels;
use App\Livewire\Settings\Company;
use App\Livewire\Settings\GoogleContacts;
use App\Livewire\Settings\QuickReplies;
use App\Livewire\Settings\Retention;
use App\Livewire\Settings\Sectors;
use App\Livewire\Settings\Tags;
use App\Livewire\Settings\Users;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/inbox')->name('home');

Route::post('webhooks/waha', WahaWebhookController::class)->middleware('throttle:webhook')->name('webhooks.waha');
Route::get('marca/logo', LogoController::class)->name('logo');

// Fora de `auth` e da manutenção de propósito: o admin acompanha a restauração mesmo com o sistema em manutenção.
Route::get('restauracao', RestorePageController::class)->name('restauracao');
Route::get('restauracao/estado', RestoreStatusController::class)->name('restauracao.estado');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('inbox', Inbox::class)->name('inbox');
    Route::get('contatos', ContactsIndex::class)->name('contatos');
    Route::get('historico', HistoryIndex::class)->name('historico');
    Route::get('midia/{message}', MessageMediaController::class)->name('midia');
    Route::get('avatares/{type}/{id}', AvatarController::class)->whereIn('type', ['users', 'contacts'])->whereNumber('id')->name('avatar');
});

Route::middleware(['auth', 'can:supervisor'])->group(function () {
    Route::get('relatorios', ReportsIndex::class)->name('relatorios');
    Route::get('configuracoes/usuarios', Users::class)->name('configuracoes.usuarios');
    Route::get('configuracoes/respostas-rapidas', QuickReplies::class)->name('configuracoes.respostas');
    Route::get('configuracoes/etiquetas', Tags::class)->name('configuracoes.etiquetas');
    Route::get('automacao', AutomationIndex::class)->name('automacao');
    Route::get('automacao/{workflow}', AutomationEditor::class)->name('automacao.editar');
});

Route::middleware(['auth', 'can:admin'])->group(function () {
    Route::get('auditoria', AuditIndex::class)->name('auditoria');
    Route::get('auditoria/sistema', AuditSystemLog::class)->name('auditoria.sistema');
    Route::get('configuracoes/canais', Channels::class)->name('configuracoes.canais');
    Route::get('configuracoes/horario', BusinessHoursPage::class)->name('configuracoes.horario');
    Route::get('configuracoes/empresa', Company::class)->name('configuracoes.empresa');
    Route::get('configuracoes/google', GoogleContacts::class)->name('configuracoes.google');
    Route::get('configuracoes/google/redirect', [GoogleConnectionController::class, 'redirect'])->name('configuracoes.google.redirect');
    Route::get('configuracoes/google/callback', [GoogleConnectionController::class, 'callback'])->name('configuracoes.google.callback');
    Route::get('configuracoes/retencao', Retention::class)->name('configuracoes.retencao');
    Route::get('configuracoes/backups', Backups::class)->name('configuracoes.backups');
    Route::get('configuracoes/backups/{name}', BackupDownloadController::class)->where('name', '[A-Za-z0-9._-]+')->name('configuracoes.backups.baixar');
    Route::get('configuracoes/setores', Sectors::class)->name('configuracoes.setores');
});

require __DIR__.'/settings.php';
