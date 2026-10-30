<?php

namespace App\Settings;

use App\Models\Setting;

/** Dados da empresa em `settings`: nome (usado na assinatura), modelo da assinatura e o SLA de primeira resposta. */
final class CompanySettings
{
    public const DEFAULT_TEMPLATE = '*{{atendente}} @ {{empresa}}:*';

    public const POSITIONS = ['inicio', 'fim'];

    public static function name(): string
    {
        $name = Setting::get('company.name');

        return is_string($name) && $name !== '' ? $name : config()->string('app.name');
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

    /**
     * @param  array{name: string, signatureEnabled: bool, signatureTemplate: string, signaturePosition: string, slaMinutes: int}  $values
     */
    public static function save(array $values): void
    {
        Setting::put('company.name', $values['name']);
        Setting::put('company.signature_enabled', $values['signatureEnabled']);
        Setting::put('company.signature_template', $values['signatureTemplate']);
        Setting::put('company.signature_position', $values['signaturePosition']);
        Setting::put('company.sla_minutes', $values['slaMinutes']);
    }
}
