<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Busca no WAHA a URL da foto do contato (a foto em si nunca é baixada). O corpo vem na Task 8. */
class SyncContactPhotoJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $contactId, public readonly int $channelId)
    {
        $this->onQueue('media');
    }

    public function handle(): void {}
}
