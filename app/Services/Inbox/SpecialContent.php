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

    private static function describe(string $card): string
    {
        preg_match('/^FN[^:\r\n]*:(.+)$/mi', $card, $name);
        preg_match_all('/^(?:item\d+\.)?TEL[^:\r\n]*:(.+)$/mi', $card, $phones);

        $phoneList = implode(', ', array_map('trim', $phones[1]));

        return 'Contato: '.(trim($name[1] ?? '') ?: 'sem nome').($phoneList === '' ? '' : ' ('.$phoneList.')');
    }
}
