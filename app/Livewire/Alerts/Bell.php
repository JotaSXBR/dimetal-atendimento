<?php

namespace App\Livewire\Alerts;

use App\Console\Commands\PurgeNotificationsCommand;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/** O sino do menu: os alertas guardados dos últimos 7 dias, com contador de não lidos. */
class Bell extends Component
{
    private const LIMIT = 20;

    /** Quantos alertas recentes entram na conta antes de juntar os de SLA repetidos. */
    private const WINDOW = 200;

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
        $notification = Auth::user()?->unreadNotifications()->whereKey($id)->first();

        if ($notification !== null) {
            $this->read($notification);
        }
    }

    /** Marca como lido e, no SLA, também os outros do mesmo ticket (a lista mostra só o mais recente por ticket). */
    private function read(DatabaseNotification $notification): void
    {
        $notification->markAsRead();

        $ticketId = $this->slaTicketId($notification);

        if ($ticketId > 0) {
            Auth::user()?->unreadNotifications()->get()
                ->filter(fn (DatabaseNotification $other): bool => $this->slaTicketId($other) === $ticketId)
                ->each(fn (DatabaseNotification $other) => $other->markAsRead());
        }
    }

    /** O ticket de um alerta de SLA (aviso ou estouro); 0 nos demais alertas. */
    private function slaTicketId(DatabaseNotification $notification): int
    {
        return in_array($notification->data['kind'] ?? null, ['sla_warn', 'sla_breached'], true) ? $this->ticketId($notification->data) : 0;
    }

    /** O clique no alerta: marca como lido e, se ele fala de um ticket que o usuário ainda pode ver, abre a conversa. */
    public function open(string $id): void
    {
        $user = Auth::user();
        $notification = $user?->notifications()->whereKey($id)->first();

        if ($user === null || $notification === null) {
            return;
        }

        $this->read($notification);

        $ticketId = $this->ticketId($notification->data);

        if ($ticketId > 0 && Ticket::query()->readableBy($user)->whereKey($ticketId)->exists()) {
            $this->redirect(route('inbox', ['ticket' => $ticketId]), navigate: true);
        }
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

        // O relógio de SLA avisa uma vez por espera: um ticket que volta a esperar gera outro par aviso + estouro. A lista mostra só o mais
        // recente por ticket (o estouro vem depois do aviso) e o contador desconta os que ficaram escondidos.
        $all = $user->notifications()
            ->where('created_at', '>=', now()->subDays(PurgeNotificationsCommand::RETENTION_DAYS))
            ->latest()
            ->limit(self::WINDOW)
            ->get();

        $seen = [];
        $items = $all->filter(function (DatabaseNotification $n) use (&$seen): bool {
            $ticketId = $this->slaTicketId($n);

            if ($ticketId === 0 || ! isset($seen[$ticketId])) {
                $seen[$ticketId] = true;

                return true;
            }

            return false;
        })->take(self::LIMIT)->values();

        $ids = $items->map(fn (DatabaseNotification $n): int => $this->ticketId($n->data))->filter()->unique()->values()->all();
        // Só mostra o protocolo de ticket que o usuário ainda pode ver (o ticket pode ter sido transferido depois do alerta).
        /** @var Collection<int|string, Ticket> $tickets */
        $tickets = $ids === [] ? new Collection : Ticket::query()->visibleTo($user)->whereIn('id', $ids)->get()->keyBy('id');

        return view('livewire.alerts.bell', [
            'items' => $items->map(fn (DatabaseNotification $n): array => $this->present($n, $tickets))->all(),
            'unread' => $this->unreadCount($user),
        ]);
    }

    /** O selo: um por ticket de SLA (como a lista) e um por alerta dos demais, sobre todos os não lidos, sem janela. */
    private function unreadCount(User $user): int
    {
        $unread = $user->unreadNotifications()->get(['id', 'data']);
        $slaTickets = $unread->map(fn (DatabaseNotification $n): int => $this->slaTicketId($n))->filter()->unique()->count();

        return $slaTickets + $unread->filter(fn (DatabaseNotification $n): bool => $this->slaTicketId($n) === 0)->count();
    }

    /** @param  array<mixed>  $data */
    private function ticketId(array $data): int
    {
        $id = $data['ticket_id'] ?? null;

        return is_numeric($id) ? (int) $id : 0;
    }

    /**
     * @param  Collection<int|string, Ticket>  $tickets
     * @return array{id: string, icon: string, tone: string, title: string, detail: string, unread: bool, time: string, at: string}
     */
    private function present(DatabaseNotification $n, Collection $tickets): array
    {
        $data = $n->data;
        $ticket = $tickets->get($this->ticketId($data));
        $detail = $ticket !== null ? 'Ticket '.($ticket->protocol ?? '#'.$ticket->id) : '';
        $state = $data['state'] ?? '';
        $channel = is_string($data['channel_name'] ?? null) ? $data['channel_name'] : 'WhatsApp';

        [$icon, $tone, $title, $detail] = match ($data['kind'] ?? '') {
            'sla_breached' => ['clock', 'danger', 'SLA estourado', $detail],
            'sla_warn' => ['clock', 'warn', 'SLA perto de estourar', $detail],
            'assigned' => ['user-plus', 'accent', 'Ticket atribuído a você', $detail],
            'connection' => match ($state) {
                'down' => ['exclamation-triangle', 'danger', 'WhatsApp desconectado', $channel],
                'number_changed' => ['arrow-path', 'warn', 'Número do WhatsApp mudou', $channel],
                default => ['check-circle', 'accent', 'WhatsApp voltou', $channel],
            },
            'backup' => match ($state) {
                'restored' => ['arrow-path', 'accent', 'Banco restaurado', 'Veja Configuração > Backup do banco'],
                'stale' => ['circle-stack', 'danger', 'Backup do banco atrasado', 'Veja Configuração > Backup do banco'],
                default => ['circle-stack', 'danger', 'Backup do banco falhou', 'Veja Configuração > Backup do banco'],
            },
            'recovery' => ['inbox-arrow-down', 'accent', ($data['state'] ?? '') === 'imported' ? 'Histórico importado' : 'Mensagens recuperadas', $this->recoveryDetail($data, $channel)],
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
            // O instante exato: o navegador recalcula o "há N minutos" (o texto do servidor congela até a próxima releitura).
            'at' => $n->created_at?->toIso8601String() ?? '',
        ];
    }

    /**
     * @param  array<mixed>  $data
     */
    private function recoveryDetail(array $data, string $channel): string
    {
        $messages = is_int($data['messages'] ?? null) ? $data['messages'] : 0;
        $conversations = is_int($data['conversations'] ?? null) ? $data['conversations'] : 0;

        $open = is_int($data['open'] ?? null) ? $data['open'] : 0;
        $detail = sprintf('%s: %d %s em %d %s', $channel, $messages, $messages === 1 ? 'mensagem' : 'mensagens', $conversations, $conversations === 1 ? 'conversa' : 'conversas');

        $detail = $open > 0 ? sprintf('%s · %d aguardando resposta', $detail, $open) : $detail;

        $limit = $data['limit'] ?? null;
        $detail = is_int($limit) ? sprintf('%s · limitada a %s mensagens', $detail, number_format($limit, 0, ',', '.')) : $detail;

        return $this->withIgnored($detail, $data['ignored'] ?? null);
    }

    /** "ignoradas: 3 de grupo, 1 sem conteúdo": o que a importação leu e não virou conversa. */
    private function withIgnored(string $detail, mixed $ignored): string
    {
        if (! is_array($ignored) || $ignored === []) {
            return $detail;
        }

        $parts = [];

        foreach ($ignored as $reason => $count) {
            $parts[] = sprintf('%d %s', is_int($count) ? $count : 0, $reason === 'grupo' || $reason === 'transmissão' ? 'de '.$reason : $reason);
        }

        return $detail.' · ignoradas: '.implode(', ', $parts);
    }
}
