<?php

namespace Tests\Feature\Livewire;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Inbox\Inbox;
use App\Livewire\Settings\Users;
use App\Models\Channel;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Media\AvatarImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class UserAvatarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config()->string('media.disk'));
    }

    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->image('eu.png', 700, 400);
    }

    private function withPhoto(User $user): User
    {
        $image = imagecreatetruecolor(40, 40);
        ob_start();
        imagepng($image);
        $user->setAvatar((new AvatarImage)->fromBinary((string) ob_get_clean(), 64));

        return $user;
    }

    public function test_usuario_troca_a_propria_foto_pelo_perfil(): void
    {
        $user = User::factory()->create(['role' => UserRole::ATENDENTE]);

        Livewire::actingAs($user)->test('pages::settings.profile')
            ->set('photo', $this->photo())
            ->call('saveAvatar')
            ->assertHasNoErrors();

        $info = getimagesizefromstring((string) Storage::disk(config()->string('media.disk'))->get((string) $user->fresh()?->avatar_path));
        $this->assertSame([256, 256, 'image/webp'], [$info[0], $info[1], $info['mime']]);
    }

    public function test_usuario_remove_a_propria_foto_pelo_perfil(): void
    {
        $user = $this->withPhoto(User::factory()->create());
        $path = (string) $user->avatar_path;

        Livewire::actingAs($user)->test('pages::settings.profile')->call('removeAvatar');

        $this->assertNull($user->fresh()?->avatar_path);
        Storage::disk(config()->string('media.disk'))->assertMissing($path);
    }

    public function test_imagem_invalida_no_perfil_mostra_erro_e_nao_grava(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test('pages::settings.profile')
            ->set('photo', UploadedFile::fake()->createWithContent('eu.png', 'nao sou imagem'))
            ->call('saveAvatar')
            ->assertHasErrors('photo');

        $this->assertNull($user->fresh()?->avatar_path);
    }

    public function test_admin_troca_e_remove_a_foto_de_outro_usuario(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $target = User::factory()->create(['role' => UserRole::ATENDENTE]);

        $page = Livewire::actingAs($admin)->test(Users::class)->call('edit', $target->id)
            ->set('photo', $this->photo())
            ->call('saveAvatar')
            ->assertHasNoErrors();
        $this->assertNotNull($target->fresh()?->avatar_path);

        $page->call('removeAvatar');
        $this->assertNull($target->fresh()?->avatar_path);
    }

    public function test_supervisor_nao_mexe_na_foto_de_admin_nem_sem_escolher_ninguem(): void
    {
        $supervisor = User::factory()->create(['role' => UserRole::SUPERVISOR]);
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);

        Livewire::actingAs($supervisor)->test(Users::class)
            ->set('editingId', $admin->id)
            ->set('photo', $this->photo())
            ->call('saveAvatar')
            ->assertForbidden();

        Livewire::actingAs($supervisor)->test(Users::class)
            ->set('photo', $this->photo())
            ->call('saveAvatar')
            ->assertHasErrors('photo');

        $this->assertNull($admin->fresh()?->avatar_path);
    }

    public function test_lista_de_usuarios_mostra_a_foto_e_o_monograma(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $withPhoto = $this->withPhoto(User::factory()->create(['role' => UserRole::ATENDENTE, 'name' => 'Ana Lima']));
        $without = User::factory()->create(['role' => UserRole::ATENDENTE, 'name' => 'Bruno Costa']);

        Livewire::actingAs($admin)->test(Users::class)
            ->assertSee('/avatares/users/'.$withPhoto->id, false)
            ->assertDontSee('/avatares/users/'.$without->id, false)
            ->assertSee('BC');
    }

    public function test_card_da_fila_mostra_a_foto_do_responsavel(): void
    {
        $owner = $this->withPhoto(User::factory()->create(['role' => UserRole::ATENDENTE]));
        Ticket::factory()->create([
            'channel_id' => Channel::factory()->create()->id,
            'status' => TicketStatus::EM_ATENDIMENTO,
            'assigned_user_id' => $owner->id,
        ]);

        Livewire::actingAs($owner)->test(Inbox::class)->assertSee('/avatares/users/'.$owner->id, false);
    }
}
