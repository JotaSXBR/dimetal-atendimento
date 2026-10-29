<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Recusa caracteres de controle e de direção de texto (bidi): não têm lugar num cadastro e o Google não os aceita. */
class NoControlChars implements ValidationRule
{
    /** @param  bool  $allowNewlines  tab, LF e CR passam (endereço e observações) */
    public function __construct(private readonly bool $allowNewlines = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $control = $this->allowNewlines ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/';

        if (preg_match($control, $value) === 1 || preg_match('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $value) === 1) {
            $fail('O campo :attribute tem caracteres que não podem ser usados.');
        }
    }
}
