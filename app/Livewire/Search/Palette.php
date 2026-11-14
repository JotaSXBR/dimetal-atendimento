<?php

namespace App\Livewire\Search;

use App\Models\Contact;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Navigation;
use App\Support\NavItem;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Component;

/** A busca Ctrl+K: telas, contatos e protocolos de ticket, sempre pelo que o usuário já pode ver (nada aqui amplia acesso). */
class Palette extends Component
{
    private const LIMIT = 5;

    public string $q = '';

    /** @return array{screens: list<array{label: string, url: string}>, contacts: list<array{label: string, detail: string, url: string}>, tickets: list<array{label: string, detail: string, url: string}>} */
    public function results(): array
    {
        $user = auth()->user();
        assert($user instanceof User);

        $term = trim($this->q);

        return [
            'screens' => $this->screens($user, $term),
            'contacts' => $term === '' || ! $user->active ? [] : $this->contacts($term),
            'tickets' => $term === '' ? [] : $this->tickets($user, $term),
        ];
    }

    /** @return list<array{label: string, url: string}> */
    private function screens(User $user, string $term): array
    {
        $fold = fn (string $text): string => Str::lower(Str::ascii($text));

        return array_values(array_map(
            fn (NavItem $item): array => ['label' => $item->label, 'url' => route($item->route)],
            array_filter(app(Navigation::class)->visible($user), fn (NavItem $item): bool => $term === '' || str_contains($fold($item->label), $fold($term))),
        ));
    }

    /** @return list<array{label: string, detail: string, url: string}> */
    private function contacts(string $term): array
    {
        return array_values(Contact::query()->search($term)->orderBy('name')->limit(self::LIMIT)->get()
            ->map(fn (Contact $contact) => ['label' => $contact->label(), 'detail' => $contact->phoneLabel(), 'url' => route('contatos', ['contato' => $contact->id])])
            ->all());
    }

    /** @return list<array{label: string, detail: string, url: string}> */
    private function tickets(User $user, string $term): array
    {
        $like = '%'.addcslashes($term, '%_\\').'%';

        return array_values(Ticket::query()->readableBy($user)->whereRaw('protocol ilike ?', [$like])->with('contact')->latest('opened_at')->limit(self::LIMIT)->get()
            ->map(fn (Ticket $ticket) => ['label' => (string) $ticket->protocol, 'detail' => $ticket->contact?->label() ?? '', 'url' => route('inbox', ['ticket' => $ticket->id])])
            ->all());
    }

    public function render(): View
    {
        return view('livewire.search.palette', ['results' => $this->results(), 'searching' => trim($this->q) !== '']);
    }
}
