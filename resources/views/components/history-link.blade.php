@props(['contact'])

{{-- "Ver todos": o Histórico filtrado pelo contato (só os encerrados que o usuário pode ver). --}}
<a href="{{ route('historico', ['contactId' => $contact->id]) }}" wire:navigate class="mt-2 inline-block text-xs text-accent-content underline-offset-2 hover:underline">Ver todos no Histórico</a>
