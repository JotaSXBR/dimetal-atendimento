<?php

namespace App\Actions\Contacts;

use App\Models\Contact;

/** Grava a edição de um contato. A primeira edição marca `edited_at`: dali em diante o contato vai ao Google. */
class SaveContact
{
    private const FIELDS = ['name', 'nickname', 'phone2', 'email', 'company', 'job_title', 'address', 'notes', 'birthday'];

    /**
     * @param  array<string, mixed>  $data  colunas editáveis; `wa_id` e qualquer outra chave são ignoradas
     */
    public function __invoke(Contact $contact, array $data): Contact
    {
        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $contact->{$field} = $this->clean($data[$field]);
            }
        }

        if (array_key_exists('urls', $data)) {
            $contact->urls = $this->cleanUrls($data['urls']);
        }

        $contact->edited_at ??= now();
        $contact->save();

        return $contact;
    }

    private function clean(mixed $value): ?string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';

        return $text === '' ? null : $text;
    }

    /**
     * @return list<string>|null
     */
    private function cleanUrls(mixed $value): ?array
    {
        $urls = [];

        foreach (is_array($value) ? $value : [] as $url) {
            $url = $this->clean($url);

            if ($url !== null) {
                $urls[] = $url;
            }
        }

        return $urls === [] ? null : $urls;
    }
}
