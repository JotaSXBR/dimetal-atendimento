<?php

namespace App\Services\Google;

/** O que o app precisa saber de um contato do Google: identidade, `etag` da fonte CONTACT e os telefones/e-mails dele. */
final readonly class GooglePerson
{
    /**
     * @param  list<string>  $phones  forma canônica (+E.164) quando o Google a traz
     * @param  list<string>  $emails
     */
    public function __construct(
        public string $resourceName,
        public string $etag,
        public array $phones,
        public array $emails,
    ) {}

    /** Lê uma `Person` da resposta do People API; sem `resourceName` a resposta é inútil. */
    public static function fromApi(mixed $data): self
    {
        $resourceName = is_array($data) ? ($data['resourceName'] ?? null) : null;

        if (! is_string($resourceName) || $resourceName === '') {
            throw new GooglePeopleException('invalid_response');
        }

        return new self($resourceName, self::etagOf($data), self::valuesOf($data, 'phoneNumbers', true), self::valuesOf($data, 'emailAddresses', false));
    }

    /**
     * @param  array<mixed>  $data
     */
    private static function etagOf(array $data): string
    {
        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $sources = is_array($metadata['sources'] ?? null) ? $metadata['sources'] : [];

        foreach ($sources as $source) {
            if (is_array($source) && ($source['type'] ?? null) === 'CONTACT' && is_string($source['etag'] ?? null)) {
                return $source['etag'];
            }
        }

        return is_string($data['etag'] ?? null) ? $data['etag'] : '';
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
