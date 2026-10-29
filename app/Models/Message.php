<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageOrigin;
use Carbon\CarbonImmutable;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Mensagem de uma conversa. Só a entrega e o id do WhatsApp mudam depois de criada.
 *
 * @property int $id
 * @property int $ticket_id
 * @property int $channel_id
 * @property int $contact_id
 * @property string|null $wa_message_id
 * @property MessageDirection $direction
 * @property string $type
 * @property string $body
 * @property MessageOrigin $origin
 * @property int|null $author_user_id
 * @property CarbonImmutable $sent_at
 * @property DeliveryStatus|null $delivery_status
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $read_at
 * @property string|null $failure_reason
 * @property string|null $failure_code
 * @property string|null $media_ref
 * @property string|null $media_mime
 * @property string|null $media_name
 * @property int|null $media_size
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'ticket_id',
    'channel_id',
    'contact_id',
    'wa_message_id',
    'direction',
    'type',
    'body',
    'origin',
    'author_user_id',
    'sent_at',
    'delivery_status',
    'delivered_at',
    'read_at',
    'failure_reason',
    'failure_code',
    'media_ref',
    'media_mime',
    'media_name',
    'media_size',
])]
class Message extends Model
{
    /**
     * O WAHA cita o id como `{fromMe}_{chat}_{id}` e o chat varia (`@c.us` ou `@lid`) entre o envio, o eco e o
     * ack. Só o trecho final identifica a mensagem; é ele que fica em `wa_message_id`.
     */
    public static function waKey(string $waId): string
    {
        return Str::afterLast($waId, '_');
    }

    public function hasMedia(): bool
    {
        return $this->type === 'media';
    }

    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => MessageDirection::class,
            'origin' => MessageOrigin::class,
            'delivery_status' => DeliveryStatus::class,
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
