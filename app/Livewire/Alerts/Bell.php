<?php

namespace App\Livewire\Alerts;

use App\Console\Commands\PurgeNotificationsCommand;
use App\Models\Ticket;
use Illuminate\Contracts\View\View;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/** O sino do menu: os alertas guardados dos últimos 7 dias, com contador de não lidos. */
class Bell extends Component
{
    private const LIMIT = 20;

    /** @return array<string, string> */
    public function getListeners(): array
    {
        return [
            'echo-private:users.'.Auth::id().',.Illuminate\\Notifications\\Events\\BroadcastNotificationCreated' => '$refresh',
            'alerts-refresh' => '$refresh',
        ];
    }

    public function markRead(string $id): void
    {
        Auth::user()?->unreadNotifications()->whereKey($id)->first()?->markAsRead();
    }

    public function markAllRead(): void
    {
        Auth::user()?->unreadNotifications()->update(['read_at' => now()]);
    }

    public function render(): View
    {
        $user = Auth::user();

        if ($user === null) {
            return view('livewire.alerts.bell', ['items' => [], 'unread' => 0]);
        }

        $items = $user->notifications()
            ->where('created_at', '>=', now()->subDays(PurgeNotificationsCommand::RETENTION_DAYS))
            ->latest()
            ->limit(self::LIMIT)
            ->get();

        $ids = $items->map(fn (DatabaseNotification $n): int => $this->ticketId($n->data))->filter()->unique()->values()->all();
        // Só mostra o protocolo de ticket que o usuário ainda pode ver (o ticket pode ter sido transferido depois do alerta).
        /** @var Collection<int|string, Ticket> $tickets */
        $tickets = $ids === [] ? new Collection : Ticket::query()->visibleTo($user)->whereIn('id', $ids)->get()->keyBy('id');

        return view('livewire.alerts.bell', [
            'items' => $items->map(fn (DatabaseNotification $n): array => $this->present($n, $tickets))->all(),
            'unread' => $user->unreadNotifications()->count(),
        ]);
    }

    /** @param  array<mixed>  $data */
    private function ticketId(array $data): int
    {
        $id = $data['ticket_id'] ?? null;

        return is_numeric($id) ? (int) $id : 0;
    }

    /**
     * @param  Collection<int|string, Ticket>  $tickets
     * @return array{id: string, icon: string, tone: string, title: string, detail: string, unread: bool, time: string}
     */
    private function present(DatabaseNotification $n, Collection $tickets): array
    {
        $data = $n->data;
        $ticket = $tickets->get($this->ticketId($data));
        $detail = $ticket !== null ? 'Ticket '.($ticket->protocol ?? '#'.$ticket->id) : '';
        $down = ($data['state'] ?? '') === 'down';
        $channel = is_string($data['channel_name'] ?? null) ? $data['channel_name'] : 'WhatsApp';

        [$icon, $tone, $title, $detail] = match ($data['kind'] ?? '') {
            'sla_breached' => ['clock', 'danger', 'SLA estourado', $detail],
            'sla_warn' => ['clock', 'warn', 'SLA perto de estourar', $detail],
            'assigned' => ['user-plus', 'accent', 'Ticket atribuído a você', $detail],
            'connection' => $down
                ? ['exclamation-triangle', 'danger', 'WhatsApp desconectado', $channel]
                : ['check-circle', 'accent', 'WhatsApp voltou', $channel],
            default => ['bell', 'accent', 'Alerta', $detail],
        };

        return [
            'id' => $n->id,
            'icon' => $icon,
            'tone' => $tone,
            'title' => $title,
            'detail' => $detail,
            'unread' => $n->read_at === null,
            'time' => $n->created_at?->diffForHumans() ?? '',
        ];
    }
}
