<?php

namespace App\Livewire\Forms;

use App\Models\Contact;
use App\Rules\NoControlChars;
use Closure;
use Livewire\Form;

/** Campos editáveis do contato: o mesmo formulário serve à ficha do inbox e à tela Contatos. */
class ContactForm extends Form
{
    public const MAX_URLS = 5;

    public string $name = '';

    public string $nickname = '';

    public string $phone2 = '';

    public string $email = '';

    public string $company = '';

    public string $jobTitle = '';

    public string $address = '';

    public string $notes = '';

    public string $birthday = '';

    /** @var array<int, string> */
    public array $urls = [];

    public function fillFrom(Contact $contact): void
    {
        $this->name = (string) $contact->name;
        $this->nickname = (string) $contact->nickname;
        $this->phone2 = (string) $contact->phone2;
        $this->email = (string) $contact->email;
        $this->company = (string) $contact->company;
        $this->jobTitle = (string) $contact->job_title;
        $this->address = (string) $contact->address;
        $this->notes = (string) $contact->notes;
        $this->birthday = $contact->birthday?->toDateString() ?? '';
        $this->urls = $contact->urls ?? [];
    }

    /**
     * @return array<string, mixed> colunas de `contacts`, prontas para `SaveContact`
     */
    public function payload(): array
    {
        return [
            'name' => $this->name,
            'nickname' => $this->nickname,
            'phone2' => $this->phone2,
            'email' => $this->email,
            'company' => $this->company,
            'job_title' => $this->jobTitle,
            'address' => $this->address,
            'notes' => $this->notes,
            'birthday' => $this->birthday,
            'urls' => $this->urls,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', new NoControlChars],
            'nickname' => ['nullable', 'string', 'max:120', new NoControlChars],
            'phone2' => ['nullable', 'string', 'max:32', new NoControlChars],
            'email' => ['nullable', 'email', 'max:255', new NoControlChars],
            'company' => ['nullable', 'string', 'max:120', new NoControlChars],
            'jobTitle' => ['nullable', 'string', 'max:120', new NoControlChars],
            'address' => ['nullable', 'string', 'max:500', new NoControlChars(allowNewlines: true)],
            'notes' => ['nullable', 'string', 'max:4000', new NoControlChars(allowNewlines: true)],
            'birthday' => ['nullable', 'date_format:Y-m-d'],
            'urls' => ['array', 'max:'.self::MAX_URLS],
            'urls.*' => ['nullable', 'string', 'max:2048', new NoControlChars, $this->plainWebAddress(...)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Informe o nome.',
            'name.max' => 'O nome pode ter até 120 caracteres.',
            'email.email' => 'E-mail inválido.',
            'birthday.date_format' => 'Data de aniversário inválida.',
            'urls.max' => 'No máximo '.self::MAX_URLS.' endereços de site.',
            'notes.max' => 'As observações podem ter até 4000 caracteres.',
            'address.max' => 'O endereço pode ter até 500 caracteres.',
        ];
    }

    /**
     * Só http(s), sem usuário e senha na URL. A assinatura da regra em closure traz o atributo.
     *
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    private function plainWebAddress(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $parts = parse_url(trim($value));

        if ($parts === false || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            $fail('Endereço de site inválido: use http:// ou https:// e sem usuário e senha.');
        }
    }
}
