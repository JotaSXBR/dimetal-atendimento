<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * @property int $id
 * @property string $key
 * @property string|null $value
 * @property string $type
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['key', 'value', 'type'])]
class Setting extends Model
{
    public static function put(string $key, mixed $value, string $type = 'string'): void
    {
        $stored = $type === 'secret'
            ? Crypt::encryptString((string) $value)
            : json_encode($value);

        static::updateOrCreate(['key' => $key], ['value' => $stored, 'type' => $type]);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::query()->where('key', $key)->first();

        if ($setting === null) {
            return $default;
        }

        if ($setting->type === 'secret') {
            return $setting->value === null ? null : Crypt::decryptString($setting->value);
        }

        return json_decode((string) $setting->value, true);
    }
}
