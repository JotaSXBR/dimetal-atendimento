<?php

namespace App\Services\Inbox;

use RuntimeException;

/** Uma conversa não pôde ser iniciada pelo app; a mensagem já vem pronta para a tela e nada foi criado. */
class ConversationNotStarted extends RuntimeException {}
