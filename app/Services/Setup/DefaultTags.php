<?php

namespace App\Services\Setup;

use App\Enums\TagColor;
use App\Enums\TagScope;
use App\Models\Setting;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;

/**
 * Etiquetas genéricas de uma instalação nova (sistema vazio). Mesmo mecanismo dos setores padrão: cria uma vez, a marca em
 * `settings` impede de recriar o que o admin apagou, e quem já tem etiqueta só recebe a marca. Nomes curtos para não poluir o card da Fila.
 */
final class DefaultTags
{
    public const MARK = 'tags.defaults_loaded';

    /** @var list<array{0: string, 1: TagScope, 2: TagColor}> */
    public const ALL = [
        ['Novo', TagScope::CONTATO, TagColor::CEU],
        ['Ativo', TagScope::CONTATO, TagColor::TURQUESA],
        ['PJ', TagScope::CONTATO, TagColor::AZUL],
        ['PF', TagScope::CONTATO, TagColor::LILAS],
        ['VIP', TagScope::CONTATO, TagColor::FUCSIA],
        ['Em atraso', TagScope::CONTATO, TagColor::ROSA],
        ['Fornecedor', TagScope::CONTATO, TagColor::PEDRA],
        ['Orçamento', TagScope::CONVERSA, TagColor::CEU],
        ['Pedido', TagScope::CONVERSA, TagColor::AZUL],
        ['Boleto', TagScope::CONVERSA, TagColor::VIOLETA],
        ['Entrega', TagScope::CONVERSA, TagColor::TURQUESA],
        ['Troca', TagScope::CONVERSA, TagColor::CIANO],
        ['Reclamação', TagScope::CONVERSA, TagColor::FUCSIA],
        ['Urgente', TagScope::CONVERSA, TagColor::ARDOSIA],
        ['Dúvida', TagScope::CONVERSA, TagColor::ANIL],
    ];

    public static function isLoaded(): bool
    {
        return Setting::get(self::MARK) === true;
    }

    /** @return bool true se esta chamada gravou a marca (false: já estava instalado) */
    public static function install(): bool
    {
        if (self::isLoaded()) {
            return false;
        }

        return DB::transaction(function (): bool {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', [self::MARK]);
            if (self::isLoaded()) {
                return false;
            }

            if (! Tag::query()->exists()) {
                foreach (self::ALL as [$name, $scope, $color]) {
                    Tag::query()->create(['name' => $name, 'scope' => $scope, 'color' => $color]);
                }
            }
            Setting::put(self::MARK, true);

            return true;
        });
    }
}
