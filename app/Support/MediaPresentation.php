<?php

namespace App\Support;

/** Como uma mídia aparece na tela: o tipo, a extensão, a etiqueta do ícone do documento e o tamanho legível. */
final class MediaPresentation
{
    /** Extensões por mime, para o arquivo que chega sem nome com extensão. */
    private const MIME_EXTENSIONS = [
        'application/pdf' => 'PDF',
        'application/msword' => 'DOC',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'DOCX',
        'application/vnd.ms-excel' => 'XLS',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'XLSX',
        'application/vnd.ms-powerpoint' => 'PPT',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'PPTX',
        'text/csv' => 'CSV',
        'application/zip' => 'ZIP',
    ];

    private const BADGES = [
        'DOCX' => ['W', 'blue'], 'DOC' => ['W', 'blue'],
        'XLSX' => ['X', 'green'], 'XLS' => ['X', 'green'], 'CSV' => ['X', 'green'],
        'PPTX' => ['P', 'orange'], 'PPT' => ['P', 'orange'],
        'PDF' => ['PDF', 'red'],
    ];

    /** `sticker` (figurinha é webp), `image`, `video`, `audio` ou `document` (qualquer outro, inclusive sem mime). */
    public static function kind(?string $mime): string
    {
        return match (true) {
            $mime === 'image/webp' => 'sticker',
            $mime !== null && str_starts_with($mime, 'image/') => 'image',
            $mime !== null && str_starts_with($mime, 'video/') => 'video',
            $mime !== null && str_starts_with($mime, 'audio/') => 'audio',
            default => 'document',
        };
    }

    /** A extensão em maiúsculas: a do nome do arquivo e, sem ela, a do mime; vazio quando nenhuma diz. */
    public static function extension(?string $name, ?string $mime): string
    {
        $fromName = $name === null ? '' : pathinfo($name, PATHINFO_EXTENSION);

        return $fromName !== '' ? strtoupper($fromName) : (self::MIME_EXTENSIONS[$mime ?? ''] ?? '');
    }

    /** @return array{label: string, tone: string} a letra do ícone e a cor (`blue`, `green`, `orange`, `red` ou `gray`) */
    public static function badge(string $extension): array
    {
        [$label, $tone] = self::BADGES[$extension] ?? ['', 'gray'];

        return ['label' => $label, 'tone' => $tone];
    }

    /** "22 KB", "1,5 MB"; nulo quando o tamanho não é conhecido. Sem `intl`, para não depender da extensão. */
    public static function size(?int $bytes): ?string
    {
        if ($bytes === null || $bytes <= 0) {
            return null;
        }

        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $value = $bytes / 1024;
        $unit = 0;

        while ($value >= 1024 && $unit < 2) {
            $value /= 1024;
            $unit++;
        }

        $text = $value >= 10 ? (string) round($value) : rtrim(rtrim(number_format($value, 1, ',', ''), '0'), ',');

        return $text.' '.['KB', 'MB', 'GB'][$unit];
    }
}
