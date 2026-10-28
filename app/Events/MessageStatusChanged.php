<?php

namespace App\Events;

use App\Models\Message;

class MessageStatusChanged extends InboxEvent
{
    public function __construct(Message $message)
    {
        parent::__construct($message->ticket_id, $message->id, $message->ticket->assigned_user_id);
    }

    protected function type(): string
    {
        return 'message.status';
    }
}
