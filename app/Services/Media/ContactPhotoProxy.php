<?php

namespace App\Services\Media;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\Ticket;
use App\Services\Inbox\SyncContactPhoto;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Mostra a foto do contato sem guardá-la: busca a URL do CDN do WhatsApp (guardada em `contacts.avatar_url`) e repassa os bytes
 * da memória ao navegador. O navegador nunca fala com o CDN e nada vai para disco. URL vencida some da tela (monograma) e pede
 * nova busca da URL ao WAHA, no ritmo de `SyncContactPhoto::queueFor` (no máximo uma por hora por contato).
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
            // Tempo curto: cada foto da tela prende um worker enquanto espera; `stream` deixa recusar pelo cabeçalho sem ler o corpo.
            $upstream = Http::timeout(5)->connectTimeout(3)->withoutRedirecting()->withOptions(['stream' => true])->get($url);
        } catch (Throwable) {
            abort(404);
        }

        if (in_array($upstream->status(), [403, 404, 410], true)) {
            $this->expire($contact);
        }

        $type = strtolower(trim(explode(';', (string) $upstream->header('Content-Type'))[0]));

        abort_unless($upstream->successful() && in_array($type, self::TYPES, true) && (int) $upstream->header('Content-Length') <= self::MAX_BYTES, 404);

        // Lê no máximo o teto + 1 byte: um servidor sem Content-Length não faz o corpo inteiro passar pela memória.
        $body = Utils::copyToString($upstream->toPsrResponse()->getBody(), self::MAX_BYTES + 1);

        abort_if(strlen($body) > self::MAX_BYTES, 404);

        return response($body, 200, [
            'Content-Type' => $type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /** O CDN recusou a URL (vencida): tira-a da tela de uma vez e pede outra ao WAHA, com a trava de 1 h do `queueFor`. */
    private function expire(Contact $contact): void
    {
        $contact->forceFill(['avatar_url' => null, 'avatar_checked_at' => null])->save();

        $channelId = Ticket::query()->where('contact_id', $contact->id)->latest('id')->value('channel_id');
        $channel = is_int($channelId) ? Channel::query()->find($channelId) : null;

        if ($channel !== null) {
            app(SyncContactPhoto::class)->queueFor($contact, $channel);
        }
    }
}
