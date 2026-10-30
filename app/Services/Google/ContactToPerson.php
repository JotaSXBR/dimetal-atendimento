<?php

namespace App\Services\Google;

use App\Models\Contact;
use App\Rules\NoControlChars;
use App\Support\PhoneFormat;

/**
 * Contato do sistema -> `Person` do People API. Só entra o que o app tem e o Google aceita: texto com caractere de
 * controle ou de direção (bidi) é descartado, URL só http(s) sem usuário e senha, e `names`, `birthdays` e `biographies`
 * levam no máximo um valor. O apelido interno nunca vai.
 */
class ContactToPerson
{
    private const FIELDS = ['names', 'phoneNumbers', 'emailAddresses', 'organizations', 'addresses', 'biographies', 'birthdays', 'urls'];

    /**
     * @return array<string, mixed>
     */
    public function map(Contact $contact): array
    {
        $person = [
            'names' => $this->single('unstructuredName', $this->text($contact->name)),
            'phoneNumbers' => $this->phones($contact),
            'emailAddresses' => $this->single('value', $this->text($contact->email)),
            'organizations' => $this->organizations($contact),
            'addresses' => $this->single('formattedValue', $this->text($contact->address, true)),
            'biographies' => $this->biographies($contact),
            'birthdays' => $this->birthdays($contact),
            'urls' => $this->urls($contact),
        ];

        return array_filter($person, fn (array $value): bool => $value !== []);
    }

    /**
     * A máscara completa: o campo que ficou vazio no app é limpo no Google junto com o resto.
     *
     * @return list<string>
     */
    public function fields(): array
    {
        return self::FIELDS;
    }

    /**
     * @param  array<mixed>  $person
     */
    public function hash(array $person): string
    {
        return hash('sha256', (string) json_encode($this->sorted($person), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** Editado e com como ser achado no Google: telefone do WhatsApp (`@c.us`) ou e-mail. */
    public function isEligible(Contact $contact): bool
    {
        return $contact->edited_at !== null && ($this->whatsappDigits($contact) !== null || $this->text($contact->email) !== null);
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private function sorted(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sorted($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /** O texto limpo, ou `null` se estiver vazio ou tiver caractere que o Google não aceita. */
    private function text(?string $value, bool $multiline = false): ?string
    {
        $value = trim((string) $value);

        return $value !== '' && NoControlChars::isClean($value, $multiline) ? $value : null;
    }

    /**
     * @return list<array<string, string>>
     */
    private function single(string $key, ?string $value): array
    {
        return $value === null ? [] : [[$key => $value]];
    }

    private function whatsappDigits(Contact $contact): ?string
    {
        $waId = (string) $contact->wa_id;

        return str_ends_with($waId, '@c.us') ? preg_replace('/\D/', '', $waId) : null;
    }

    /**
     * @return list<array{value: string}>
     */
    private function phones(Contact $contact): array
    {
        $digits = $this->whatsappDigits($contact);
        $phones = $digits === null ? [] : [['value' => '+'.$digits]];
        $second = $this->text($contact->phone2);

        if ($second !== null && ! $this->samePhone($second, $digits)) {
            $phones[] = ['value' => $second];
        }

        return $phones;
    }

    /** O segundo telefone repete o do WhatsApp se os dígitos batem, com ou sem o 55 que a pessoa não digitou. */
    private function samePhone(string $second, ?string $digits): bool
    {
        if ($digits === null) {
            return false;
        }

        $typed = preg_replace('/\D/', '', $second);
        $normalized = preg_replace('/\D/', '', (string) PhoneFormat::toWaId($second));

        return $typed === $digits || $normalized === $digits;
    }

    /**
     * @return list<array{name?: string, title?: string}>
     */
    private function organizations(Contact $contact): array
    {
        $organization = array_filter(['name' => $this->text($contact->company), 'title' => $this->text($contact->job_title)]);

        return $organization === [] ? [] : [$organization];
    }

    /**
     * @return list<array{value: string, contentType: string}>
     */
    private function biographies(Contact $contact): array
    {
        $notes = $this->text($contact->notes, true);

        return $notes === null ? [] : [['value' => $notes, 'contentType' => 'TEXT_PLAIN']];
    }

    /**
     * @return list<array{date: array{year: int, month: int, day: int}}>
     */
    private function birthdays(Contact $contact): array
    {
        $birthday = $contact->birthday;

        return $birthday === null ? [] : [['date' => ['year' => $birthday->year, 'month' => $birthday->month, 'day' => $birthday->day]]];
    }

    /**
     * @return list<array{value: string}>
     */
    private function urls(Contact $contact): array
    {
        $urls = [];

        foreach ($contact->urls ?? [] as $url) {
            $url = $this->text($url);
            $parts = $url === null ? false : parse_url($url);

            if (is_array($parts) && in_array($parts['scheme'] ?? '', ['http', 'https'], true) && isset($parts['host']) && ! isset($parts['user']) && ! isset($parts['pass'])) {
                $urls[] = ['value' => (string) $url];
            }
        }

        return $urls;
    }
}
