<?php

namespace App\Services\Media;

use App\Jobs\SyncContactPhotoJob;
use App\Models\Contact;
use App\Models\Ticket;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Mostra a foto do contato sem guardá-la: busca a URL do CDN do WhatsApp (guardada em `contacts.avatar_url`) e repassa os bytes
 * da memória ao navegador. O navegador nunca fala com o CDN e nada vai para disco. URL vencida pede nova busca da URL ao WAHA.
 */
class ContactPhotoProxy
{
    private const MAX_BYTES = 2_097_152;

    private const TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function response(Contact $contact): Response
    {
        $url = $contact->avatar_url;

        abort_if($url === null || ! str_starts_with($url, 'https://'), 404);

        try {
            $upstream = Http::timeout(10)->withoutRedirecting()->get($url);
        } catch (Throwable) {
            abort(404);
        }

        if (in_array($upstream->status(), [403, 404, 410], true)) {
            $this->askForFreshUrl($contact);
        }

        $type = strtolower(trim(explode(';', (string) $upstream->header('Content-Type'))[0]));
        $body = $upstream->body();

        abort_unless($upstream->successful() && in_array($type, self::TYPES, true) && strlen($body) <= self::MAX_BYTES, 404);

        return response($body, 200, [
            'Content-Type' => $type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    private function askForFreshUrl(Contact $contact): void
    {
        $channelId = Ticket::query()->where('contact_id', $contact->id)->latest('id')->value('channel_id');

        if (is_int($channelId)) {
            SyncContactPhotoJob::dispatch($contact->id, $channelId);
        }
    }
}
