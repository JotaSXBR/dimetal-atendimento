<?php

namespace App\Http\Controllers;

use App\Services\Backup\BackupCatalog;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Entrega um backup ao admin em stream. O nome só vale se estiver na lista do catálogo: nunca vira caminho de arquivo. */
class BackupDownloadController extends Controller
{
    public function __invoke(BackupCatalog $catalog, string $name): StreamedResponse
    {
        $file = $catalog->find($name);
        $stream = $file === null ? null : $catalog->disk()->readStream($file->name);

        abort_if($file === null || $stream === null, 404);

        Log::notice('Backup baixado', ['user_id' => auth()->id(), 'file' => $file->name]);

        return response()->streamDownload(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, $file->name, [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => (string) $file->size,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
