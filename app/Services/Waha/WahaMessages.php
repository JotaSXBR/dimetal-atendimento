<?php

namespace App\Services\Waha;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * Lista as mensagens de um canal no WAHA por janela de horário (`chats/all/messages`), página a página. Fica fora do
 * `WahaClient`, que já tem um método por endpoint de sessão.
 */
class WahaMessages
{
    private const PAGE_SIZE = 100;

    private const MAX_PAGES = 200;

    /** Fatia de 24 h: de `lte - 86400` a `lte`, os dois inclusive; a próxima (mais antiga) termina um segundo antes. */
    private const SLICE_SECONDS = 86400;

    public function __construct(private readonly WahaClient $client) {}

    /**
     * Cada item tem o formato do `payload` do webhook. A mídia não é baixada aqui: `ResolveMessageMediaJob` cuida dela.
     * A janela vai em fatias de 24 h (cada uma com o seu teto de páginas), para um dia cheio não esconder os outros.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function between(CarbonInterface $from, CarbonInterface $to): \Generator
    {
        $first = $from->getTimestamp();

        // Da fatia mais nova para a mais antiga: se um teto parar a leitura, o que sobra é o mais recente.
        for ($end = $to->getTimestamp(); $end >= $first; $end = $start - 1) {
            $start = max($end - self::SLICE_SECONDS, $first);

            yield from $this->slice($start, $end);
        }
    }

    /** @return \Generator<int, array<string, mixed>> */
    private function slice(int $from, int $to): \Generator
    {
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $this->client->http()->get('/api/'.$this->client->sessionName().'/chats/all/messages', [
                'filter.timestamp.gte' => $from,
                'filter.timestamp.lte' => $to,
                'limit' => self::PAGE_SIZE,
                'offset' => $page * self::PAGE_SIZE,
                'downloadMedia' => 'false',
            ]);
            $this->client->check($response);

            $items = array_values(array_map(
                fn (array $item): array => array_filter($item, is_string(...), ARRAY_FILTER_USE_KEY),
                array_filter((array) $response->json(), is_array(...)),
            ));

            yield from $items;

            if (count($items) < self::PAGE_SIZE) {
                return;
            }
        }

        Log::warning('Listagem do WAHA bateu o teto de páginas; mensagens desta fatia podem ter ficado de fora', ['sessao' => $this->client->sessionName(), 'de' => $from, 'ate' => $to]);
    }
}
