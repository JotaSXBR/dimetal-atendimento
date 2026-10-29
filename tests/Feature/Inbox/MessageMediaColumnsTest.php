<?php

namespace Tests\Feature\Inbox;

use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageMediaColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_mensagem_guarda_a_referencia_da_midia(): void
    {
        $id = Message::factory()->create([
            'type' => 'media',
            'media_ref' => 'a.ogg',
            'media_mime' => 'audio/ogg',
            'media_name' => 'a.ogg',
            'media_size' => 1234,
        ])->id;

        $message = Message::findOrFail($id);

        $this->assertSame('a.ogg', $message->media_ref);
        $this->assertSame('audio/ogg', $message->media_mime);
        $this->assertSame('a.ogg', $message->media_name);
        $this->assertSame(1234, $message->media_size);
        $this->assertTrue($message->hasMedia());
    }

    public function test_mensagem_de_texto_nao_tem_midia(): void
    {
        $message = Message::factory()->create();

        $this->assertFalse($message->hasMedia());
        $this->assertNull($message->media_ref);
    }
}
