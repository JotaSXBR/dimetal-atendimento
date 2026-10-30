<?php

namespace App\Services\Google;

/** O que o app precisa saber de um contato do Google: identidade, `etag` da fonte CONTACT e os telefones/e-mails dele. */
final readonly class GooglePerson
{
    /**
     * @param  list<string>  $phones  forma canônica (+E.164) quando o Google a traz
     * @param  list<string>  $emails
     * @param  list<string>  $sources  tipos de fonte que o Google informou (CONTACT, PROFILE...), para explicar um `etag` ausente
     * @param  string  $sourceId  `id` da fonte CONTACT, que o Google exige junto do `etag` para saber qual fonte se atualiza
     */
    public function __construct(
        public string $resourceName,
        public string $etag,
        public array $phones,
        public array $emails,
        public array $sources = [],
        public string $sourceId = '',
    ) {}

    /** Lê uma `Person` da resposta do People API; sem `resourceName` a resposta é inútil. */
    public static function fromApi(mixed $data): self
    {
        $resourceName = is_array($data) ? ($data['resourceName'] ?? null) : null;

        if (! is_string($resourceName) || $resourceName === '') {
            throw new GooglePeopleException('invalid_response');
        }

        $source = self::contactSource($data);

        return new self(
            $resourceName,
            is_string($source['etag'] ?? null) ? $source['etag'] : '',
            self::valuesOf($data, 'phoneNumbers', true),
            self::valuesOf($data, 'emailAddresses', false),
            self::sourceTypes($data),
            is_string($source['id'] ?? null) ? $source['id'] : '',
        );
    }

    /**
     * @param  array<mixed>  $data
     * @return list<string>
     */
    private static function sourceTypes(array $data): array
    {
        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $types = [];

        foreach (is_array($metadata['sources'] ?? null) ? $metadata['sources'] : [] as $source) {
            if (is_array($source) && is_string($source['type'] ?? null)) {
                $types[] = $source['type'];
            }
        }

        return $types;
    }

    /**
     * A fonte CONTACT da pessoa (`type`, `id`, `etag`). O `etag` do topo da pessoa é de outro recurso e não serve para
     * atualizar um contato: sem a fonte CONTACT o `etag` fica vazio e quem atualiza relê o contato.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private static function contactSource(array $data): array
    {
        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];

        foreach (is_array($metadata['sources'] ?? null) ? $metadata['sources'] : [] as $source) {
            if (is_array($source) && ($source['type'] ?? null) === 'CONTACT') {
                return $source;
            }
        }

        return [];
    }

    /**
     * @param  array<mixed>  $data
     * @return list<string>
     */
    private static function valuesOf(array $data, string $field, bool $canonical): array
    {
        $values = [];

        foreach (is_array($data[$field] ?? null) ? $data[$field] : [] as $item) {
            $value = is_array($item) ? ($canonical && is_string($item['canonicalForm'] ?? null) ? $item['canonicalForm'] : ($item['value'] ?? null)) : null;

            if (is_string($value) && $value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }
}
