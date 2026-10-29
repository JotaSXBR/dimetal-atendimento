<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Uma faixa de atendimento num dia da semana (0 = domingo ... 6 = sábado). Horários são texto `H:i:s` no fuso de exibição.
 *
 * @property int $id
 * @property int $weekday
 * @property string $opens_at
 * @property string $closes_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['weekday', 'opens_at', 'closes_at'])]
class BusinessHour extends Model {}
