<?php

namespace App\Services\Waha;

use Carbon\CarbonInterface;

/**
 * Lista as mensagens de um canal no WAHA por janela de horário (`chats/all/messages`), página a página. Fica fora do
 * `WahaClient`, que já tem um método por endpoint de sessão.
 */
class WahaMessages
{
    private const PAGE_SIZE = 100;

    private const MAX_PAGES = 200;

    public function __construct(private readonly WahaClient $client) {}

    /**
     * Cada item tem o formato do `payload` do webhook. A mídia não é baixada aqui: `ResolveMessageMediaJob` cuida dela.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function between(CarbonInterface $from, CarbonInterface $to): \Generator
    {
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $this->client->http()->get('/api/'.$this->client->sessionName().'/chats/all/messages', [
                'filter.timestamp.gte' => $from->getTimestamp(),
                'filter.timestamp.lte' => $to->getTimestamp(),
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
    }
}
