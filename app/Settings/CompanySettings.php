<?php

namespace App\Settings;

use App\Models\Setting;
use App\Services\Media\AvatarStorage;

/** Dados da empresa em `settings`: nome (usado na assinatura), modelo da assinatura e o SLA de primeira resposta. */
final class CompanySettings
{
    public const DEFAULT_TEMPLATE = '*{{atendente}} @ {{empresa}}:*';

    public const POSITIONS = ['inicio', 'fim'];

    public static function name(): string
    {
        $name = Setting::get('company.name');

        if (is_string($name) && $name !== '') {
            return $name;
        }

        $app = config('app.name');

        return is_string($app) && $app !== '' ? $app : 'Laravel';
    }

    public static function logoPath(): ?string
    {
        $path = Setting::get('company.logo_path');

        return is_string($path) && $path !== '' ? $path : null;
    }

    public static function logoVersion(): ?string
    {
        $version = Setting::get('company.logo_version');

        return is_string($version) && $version !== '' ? $version : null;
    }

    /** URL pública do logo (a tela de login o mostra); a versão na URL força o navegador a buscar de novo ao trocar a imagem. */
    public static function logoUrl(): ?string
    {
        return self::logoPath() === null ? null : route('logo', ['v' => self::logoVersion()]);
    }

    /** Grava o logo novo e só depois apaga o antigo. */
    public static function setLogo(string $webp): void
    {
        $storage = app(AvatarStorage::class);
        $old = self::logoPath();
        $stored = $storage->store('branding', $webp);

        Setting::put('company.logo_path', $stored['path']);
        Setting::put('company.logo_version', $stored['version']);
        $storage->delete($old);
    }

    public static function clearLogo(): void
    {
        $old = self::logoPath();

        Setting::put('company.logo_path', null);
        Setting::put('company.logo_version', null);
        app(AvatarStorage::class)->delete($old);
    }

    public static function signatureEnabled(): bool
    {
        return (bool) Setting::get('company.signature_enabled', true);
    }

    public static function signatureTemplate(): string
    {
        $template = Setting::get('company.signature_template');

        return is_string($template) && $template !== '' ? $template : self::DEFAULT_TEMPLATE;
    }

    /** `inicio` (estilo Chatwoot: a assinatura abre a mensagem) ou `fim`. */
    public static function signaturePosition(): string
    {
        $position = Setting::get('company.signature_position');

        return in_array($position, self::POSITIONS, true) ? $position : 'inicio';
    }

    public static function slaMinutes(): int
    {
        $minutes = Setting::get('company.sla_minutes');

        return is_int($minutes) && $minutes > 0 ? $minutes : 10;
    }

    private const KEYS = [
        'name' => 'company.name',
        'signatureEnabled' => 'company.signature_enabled',
        'signatureTemplate' => 'company.signature_template',
        'signaturePosition' => 'company.signature_position',
        'slaMinutes' => 'company.sla_minutes',
    ];

    /**
     * Grava só o que vier em `$values`: Empresa e Horário e SLA salvam partes diferentes e não se sobrescrevem.
     *
     * @param  array{name?: string, signatureEnabled?: bool, signatureTemplate?: string, signaturePosition?: string, slaMinutes?: int}  $values
     */
    public static function save(array $values): void
    {
        foreach (self::KEYS as $field => $key) {
            if (array_key_exists($field, $values)) {
                Setting::put($key, $values[$field]);
            }
        }
    }
}
