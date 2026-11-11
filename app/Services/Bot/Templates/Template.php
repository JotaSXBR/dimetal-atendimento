<?php

namespace App\Services\Bot\Templates;

/** Um modelo de segmento da galeria. O grafo marca o setor desejado em `transferir.data.setor` (o nome); quem resolve é `WorkflowTemplates`. */
interface Template
{
    public static function key(): string;

    public static function name(): string;

    /** O que o modelo faz e o que não faz. */
    public static function description(): string;

    /** @return array<string, mixed> */
    public static function graph(): array;
}
