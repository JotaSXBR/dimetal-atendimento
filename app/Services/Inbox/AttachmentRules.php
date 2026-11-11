<?php

namespace App\Services\Inbox;

use finfo;
use Illuminate\Http\UploadedFile;

/**
 * O que um atendente pode anexar. O tipo vem do CONTEÚDO do arquivo (libmagic), nunca da extensão nem do tipo que o
 * navegador declarou, e a extensão ainda precisa combinar com o conteúdo: um executável renomeado para `.pdf` e um PDF
 * chamado `.exe` são recusados.
 */
final class AttachmentRules
{
    public const MAX_BYTES = 16 * 1024 * 1024;

    public const MAX_FILES = 10;

    /**
     * Tipo detectado no conteúdo => extensões aceitas. O libmagic ora chama o `.docx` de zip, o `.doc` de CDFV2 e o
     * `.csv` de texto puro; as extensões amarram cada caso (texto puro só vale como `.csv`).
     *
     * @var array<string, list<string>>
     */
    private const TYPES = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'application/pdf' => ['pdf'],
        'application/msword' => ['doc'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
        'application/vnd.ms-excel' => ['xls'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
        'application/x-ole-storage' => ['doc', 'xls'],
        'application/cdfv2' => ['doc', 'xls'],
        'application/zip' => ['docx', 'xlsx'],
        'text/plain' => ['csv'],
        'text/csv' => ['csv'],
        'application/csv' => ['csv'],
        'audio/mpeg' => ['mp3'],
        'audio/ogg' => ['ogg', 'oga', 'opus'],
        'application/ogg' => ['ogg', 'oga', 'opus'],
        'audio/mp4' => ['m4a'],
        'audio/x-m4a' => ['m4a'],
        'video/mp4' => ['mp4', 'm4a'],
    ];

    /**
     * Gravação do microfone: só OGG/Opus mono, que o navegador monta sem recodificar (resources/js/voice-config.js) e o
     * WAHA envia sem converter. Qualquer outra coisa é recusada: o caminho antigo, convertido pelo WAHA, perdia a onda.
     *
     * @var array<string, list<string>>
     */
    private const VOICE_TYPES = [
        'audio/ogg' => ['ogg'],
        'application/ogg' => ['ogg'],
    ];

    /** Mensagem de erro em pt-BR, ou `null` quando o arquivo pode ser enviado. */
    public static function validate(UploadedFile $file, bool $voice = false): ?string
    {
        $info = self::inspect($file, $voice);

        return is_string($info) ? $info : null;
    }

    /**
     * @return array{mime: string, ext: string, kind: string, name: string}|string o que o arquivo é, ou a mensagem de erro
     */
    public static function inspect(UploadedFile $file, bool $voice = false): array|string
    {
        $name = self::safeName($file->getClientOriginalName());

        if (! $file->isValid() || $file->getRealPath() === false) {
            return "Não foi possível receber o arquivo {$name}.";
        }

        $size = $file->getSize();

        if ($size === 0) {
            return "O arquivo {$name} está vazio.";
        }

        if ($size > self::MAX_BYTES) {
            return "O arquivo {$name} passa de 16 MB.";
        }

        $detected = (new finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        $mime = is_string($detected) ? strtolower(trim(explode(';', $detected)[0])) : '';
        $ext = strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));

        if (! self::accepts($file, $mime, $ext, $voice)) {
            return $voice
                ? "A gravação {$name} não é OGG/Opus mono. Grave de novo no Chrome, Edge ou Firefox."
                : "O arquivo {$name} não é de um tipo aceito (fotos, PDF, Word, Excel, áudio e vídeo MP4).";
        }

        $kind = self::kind($mime, $ext, $voice);

        return ['mime' => self::normalizedMime($mime, $kind, $ext), 'ext' => $ext, 'kind' => $kind, 'name' => $name];
    }

    /** Tipo e extensão combinam; na gravação do microfone o cabeçalho ainda precisa ser Opus mono. */
    private static function accepts(UploadedFile $file, string $mime, string $ext, bool $voice): bool
    {
        $accepted = ($voice ? self::VOICE_TYPES : self::TYPES)[$mime] ?? null;

        if ($accepted === null || ! in_array($ext, $accepted, true)) {
            return false;
        }

        return ! $voice || OggOpus::isMono((string) file_get_contents((string) $file->getRealPath(), false, null, 0, 38));
    }

    /** `image`, `video`, `audio` ou `document`, pelo endpoint que o WAHA exige para cada um. */
    public static function kind(string $mime, string $ext, bool $voice = false): string
    {
        return match (true) {
            str_starts_with($mime, 'image/') => 'image',
            $voice, str_starts_with($mime, 'audio/'), $mime === 'application/ogg', $ext === 'm4a' => 'audio',
            $mime === 'video/mp4' => 'video',
            default => 'document',
        };
    }

    /** O mesmo critério de `kind`, a partir do tipo já gravado na mensagem. */
    public static function kindOfStored(string $mime): string
    {
        return match (true) {
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'audio/') => 'audio',
            str_starts_with($mime, 'video/') => 'video',
            default => 'document',
        };
    }

    /** Nome sem caminho nem caractere de controle, no máximo 100 caracteres. */
    public static function safeName(string $name): string
    {
        $base = basename(str_replace('\\', '/', $name));
        $base = (string) preg_replace('/[\x00-\x1f\x7f]+/u', '', $base);
        $base = trim($base);

        if ($base === '' || $base === '.' || $base === '..') {
            return 'arquivo';
        }

        return mb_substr($base, 0, 100);
    }

    private static function normalizedMime(string $mime, string $kind, string $ext): string
    {
        return match (true) {
            $kind === 'audio' && str_starts_with($mime, 'video/') => 'audio/'.substr($mime, 6),
            $kind === 'audio' && $mime === 'application/ogg' => 'audio/ogg',
            $mime === 'application/zip' && $ext === 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $mime === 'application/zip' && $ext === 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            in_array($mime, ['application/x-ole-storage', 'application/cdfv2'], true) && $ext === 'doc' => 'application/msword',
            in_array($mime, ['application/x-ole-storage', 'application/cdfv2'], true) && $ext === 'xls' => 'application/vnd.ms-excel',
            in_array($mime, ['text/plain', 'application/csv'], true) => 'text/csv',
            default => $mime,
        };
    }
}
