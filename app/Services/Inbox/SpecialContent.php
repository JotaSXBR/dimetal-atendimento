<?php

namespace App\Services\Inbox;

use Illuminate\Support\Str;

/**
 * Conteúdos de mensagem do WAHA que não são texto nem arquivo: visualização única e cartão de contato (vCard).
 */
final class SpecialContent
{
    private const VIEW_ONCE_FLAGS = ['IsViewOnce', 'IsViewOnceV2', 'IsViewOnceV2Extension'];

    private const VIEW_ONCE_WRAPPERS = ['viewOnceMessage', 'viewOnceMessageV2', 'viewOnceMessageV2Extension'];

    /**
     * Conteúdo que não é texto nem arquivo, ou `null` quando a mensagem segue o caminho normal. Visualização única
     * vem primeiro e vale mesmo com `hasMedia`: o arquivo nunca é lido, só o tipo (para o aviso dizer "foto", "vídeo").
     *
     * @param  array<array-key, mixed>  $data
     * @return array{type: string, body: string, media: IncomingMedia}|null
     */
    public static function classify(array $data, bool $hasMedia): ?array
    {
        if (self::isViewOnce($data)) {
            $mime = data_get($data, 'media.mimetype');

            return ['type' => 'view_once', 'body' => '', 'media' => new IncomingMedia(null, is_string($mime) ? trim(explode(';', $mime)[0]) : null, null)];
        }

        if ($hasMedia || (is_string($data['body'] ?? null) && $data['body'] !== '')) {
            return null;
        }

        $contact = self::contactSummary($data);

        if ($contact !== '') {
            return ['type' => 'contact', 'body' => $contact, 'media' => new IncomingMedia(null, null, null)];
        }

        $location = self::location($data);

        return $location === null ? null : ['type' => 'location', 'body' => $location['coordinates'], 'media' => new IncomingMedia(null, null, null, $location['name'])];
    }

    /**
     * Coordenadas validadas (viram um link de mapa na tela) e o nome do local. O formato do GOWS não está documentado:
     * aceita `latitude`/`longitude`, `lat`/`lng` e os nomes do protocolo (`degreesLatitude`/`degreesLongitude`).
     *
     * @param  array<array-key, mixed>  $data
     * @return array{coordinates: string, name: ?string}|null
     */
    public static function location(array $data): ?array
    {
        $location = $data['location'] ?? null;

        if (! is_array($location)) {
            return null;
        }

        $lat = $location['latitude'] ?? $location['lat'] ?? $location['degreesLatitude'] ?? null;
        $lng = $location['longitude'] ?? $location['lng'] ?? $location['degreesLongitude'] ?? null;

        if (! is_numeric($lat) || ! is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
            return null;
        }

        $label = $location['name'] ?? $location['address'] ?? null;

        return ['coordinates' => round((float) $lat, 6).','.round((float) $lng, 6), 'name' => is_string($label) && $label !== '' ? Str::limit($label, 255, '') : null];
    }

    /**
     * Visualização única: o conteúdo nunca é lido nem guardado, só o aviso. O GOWS marca em `_data` (`IsViewOnce*`);
     * quando o WhatsApp não entrega o conteúdo a aparelhos vinculados, chega um aviso "indisponível".
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function isViewOnce(array $data): bool
    {
        foreach (self::VIEW_ONCE_FLAGS as $flag) {
            if (data_get($data, '_data.'.$flag) === true) {
                return true;
            }
        }

        $message = data_get($data, '_data.Message');
        $wrapped = is_array($message) && array_intersect(array_keys($message), self::VIEW_ONCE_WRAPPERS) !== [];

        return $wrapped || data_get($data, '_data.UnavailableType') === 'view_once' || data_get($data, '_data.Info.UnavailableType') === 'view_once';
    }

    /**
     * Uma linha por cartão: "Contato: Nome (telefone, telefone)". Vazio quando o evento não traz cartões.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function contactSummary(array $data): string
    {
        $cards = $data['vCards'] ?? null;
        $lines = [];

        foreach (is_array($cards) ? $cards : [] as $card) {
            if (is_string($card)) {
                $lines[] = self::describe($card);
            }
        }

        return Str::limit(implode("\n", $lines), 1000, '…');
    }

    /**
     * Lê de volta o texto gravado por `contactSummary` para a tela montar um cartão por contato.
     *
     * @return list<array{name: string, initials: string, phones: list<string>}>
     */
    public static function contacts(string $body): array
    {
        $contacts = [];

        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            if (preg_match('/^Contato: (.*?)(?: \(([^()]*)\))?$/u', trim($line), $match) !== 1) {
                continue;
            }

            $name = trim($match[1]);
            $phones = array_values(array_filter(array_map('trim', explode(',', $match[2] ?? '')), fn (string $phone): bool => preg_match('/\d/', $phone) === 1));

            // "(Vendas)" no fim do nome não é telefone: sem dígito, volta para o nome.
            if (isset($match[2]) && $match[2] !== '' && $phones === []) {
                $name .= ' ('.$match[2].')';
            }

            $contacts[] = ['name' => $name, 'initials' => self::initials($name), 'phones' => $phones];
        }

        return $contacts;
    }

    private static function initials(string $name): string
    {
        // Primeira letra de cada uma das duas primeiras palavras; "(Vendas)" conta como "V", não como "(".
        preg_match_all('/(?<![\p{L}\p{N}])\p{L}/u', $name, $letters);

        return mb_strtoupper(implode('', array_slice($letters[0], 0, 2))) ?: '?';
    }

    private static function describe(string $card): string
    {
        preg_match('/^FN[^:\r\n]*:(.+)$/mi', $card, $name);
        preg_match_all('/^(?:item\d+\.)?TEL[^:\r\n]*:(.+)$/mi', $card, $phones);

        $phoneList = implode(', ', array_map('trim', $phones[1]));

        return 'Contato: '.(trim($name[1] ?? '') ?: 'sem nome').($phoneList === '' ? '' : ' ('.$phoneList.')');
    }
}
