<?php

namespace Tests\Feature\Waha;

use App\Models\Channel;
use App\Models\User;
use App\Notifications\ChannelNumberChanged;
use App\Services\Waha\ChannelPhone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ChannelPhoneTest extends TestCase
{
    use RefreshDatabase;

    private const NUMBER = '5516999990001';

    public function test_digits_normaliza_os_formatos(): void
    {
        $this->assertSame(self::NUMBER, ChannelPhone::digits('5516999990001@c.us'));
        $this->assertSame(self::NUMBER, ChannelPhone::digits('5516999990001:12@s.whatsapp.net'));
        $this->assertSame(self::NUMBER, ChannelPhone::digits('5516999990001'));
        $this->assertNull(ChannelPhone::digits(''));
        $this->assertNull(ChannelPhone::digits(null));
        $this->assertNull(ChannelPhone::digits('123456789012345@lid'), 'id @lid não é telefone');
    }

    public function test_primeira_gravacao_nao_alerta(): void
    {
        Notification::fake();
        User::factory()->create();
        $channel = Channel::factory()->connected()->create(['phone' => null]);

        app(ChannelPhone::class)->sync($channel, self::NUMBER.'@c.us');

        $fresh = $channel->fresh();
        $this->assertSame(self::NUMBER, $fresh?->phone);
        $this->assertNull($fresh?->previous_phone);
        $this->assertNull($fresh?->phone_changed_at);
        Notification::assertNothingSent();
    }

    public function test_numero_igual_ou_vazio_nao_muda_nada(): void
    {
        Notification::fake();
        User::factory()->create();
        $channel = Channel::factory()->connected()->create(['phone' => self::NUMBER]);

        app(ChannelPhone::class)->sync($channel, self::NUMBER.'@c.us');
        app(ChannelPhone::class)->sync($channel, '');
        app(ChannelPhone::class)->sync($channel, null);

        $this->assertSame(self::NUMBER, $channel->fresh()?->phone);
        $this->assertNull($channel->fresh()?->phone_changed_at);
        Notification::assertNothingSent();
    }

    public function test_numero_gravado_formatado_com_os_mesmos_digitos_nao_alerta(): void
    {
        Notification::fake();
        User::factory()->create();
        $channel = Channel::factory()->connected()->create(['phone' => '+55 16 99999-0001']);

        app(ChannelPhone::class)->sync($channel, self::NUMBER.'@c.us');

        $this->assertSame(self::NUMBER, $channel->fresh()?->phone);
        Notification::assertNothingSent();
    }

    public function test_troca_de_numero_atualiza_guarda_o_anterior_e_alerta_uma_vez(): void
    {
        Notification::fake();
        $users = User::factory()->count(2)->create();
        $channel = Channel::factory()->connected()->create(['phone' => self::NUMBER]);

        app(ChannelPhone::class)->sync($channel, '5516988887777@c.us');
        app(ChannelPhone::class)->sync($channel->fresh(), '5516988887777@c.us');

        $fresh = $channel->fresh();
        $this->assertSame('5516988887777', $fresh?->phone);
        $this->assertSame(self::NUMBER, $fresh?->previous_phone);
        $this->assertNotNull($fresh?->phone_changed_at);
        Notification::assertSentToTimes($users[0], ChannelNumberChanged::class, 1);
        Notification::assertSentToTimes($users[1], ChannelNumberChanged::class, 1);
    }
}
