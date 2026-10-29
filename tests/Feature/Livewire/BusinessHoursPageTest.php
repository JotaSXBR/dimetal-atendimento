<?php

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Livewire\Settings\BusinessHoursPage;
use App\Models\BusinessHour;
use App\Models\Holiday;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BusinessHoursPageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::ADMIN]);
    }

    /** @return array<string, array{UserRole}> */
    public static function papeisSemAcesso(): array
    {
        return ['supervisor' => [UserRole::SUPERVISOR], 'atendente' => [UserRole::ATENDENTE]];
    }

    #[DataProvider('papeisSemAcesso')]
    public function test_so_admin_entra(UserRole $role): void
    {
        $this->actingAs(User::factory()->create(['role' => $role]))->get('/configuracoes/horario')->assertForbidden();
    }

    public function test_admin_abre_a_tela_com_as_faixas_gravadas(): void
    {
        BusinessHour::create(['weekday' => 1, 'opens_at' => '08:00', 'closes_at' => '12:00']);
        BusinessHour::create(['weekday' => 1, 'opens_at' => '13:00', 'closes_at' => '18:00']);

        Livewire::actingAs($this->admin())->test(BusinessHoursPage::class)
            ->assertSet('days.1.0.opens', '08:00')
            ->assertSet('days.1.1.closes', '18:00');
    }

    public function test_salva_varias_faixas_no_mesmo_dia_e_substitui_as_antigas(): void
    {
        BusinessHour::create(['weekday' => 3, 'opens_at' => '07:00', 'closes_at' => '09:00']);

        Livewire::actingAs($this->admin())->test(BusinessHoursPage::class)
            ->set('days', [
                1 => [['opens' => '08:00', 'closes' => '12:00'], ['opens' => '13:00', 'closes' => '18:00']],
                6 => [['opens' => '08:00', 'closes' => '12:00']],
            ])
            ->call('saveHours')
            ->assertHasNoErrors();

        $this->assertSame(3, BusinessHour::query()->count());
        $this->assertSame(0, BusinessHour::query()->where('weekday', 3)->count());
        $this->assertSame(2, BusinessHour::query()->where('weekday', 1)->count());
    }

    /** @return array<string, array{array<int, list<array{opens: string, closes: string}>>}> */
    public static function faixasInvalidas(): array
    {
        return [
            'sobreposta' => [[1 => [['opens' => '08:00', 'closes' => '12:00'], ['opens' => '11:00', 'closes' => '14:00']]]],
            'invertida' => [[1 => [['opens' => '18:00', 'closes' => '08:00']]]],
            'igual' => [[1 => [['opens' => '08:00', 'closes' => '08:00']]]],
            'hora invalida' => [[1 => [['opens' => '8h', 'closes' => '12:00']]]],
            'mais de quatro' => [[1 => [
                ['opens' => '06:00', 'closes' => '07:00'], ['opens' => '08:00', 'closes' => '09:00'],
                ['opens' => '10:00', 'closes' => '11:00'], ['opens' => '12:00', 'closes' => '13:00'],
                ['opens' => '14:00', 'closes' => '15:00'],
            ]]],
        ];
    }

    /**
     * @param  array<int, list<array{opens: string, closes: string}>>  $days
     */
    #[DataProvider('faixasInvalidas')]
    public function test_recusa_faixa_invalida_e_nao_grava_nada(array $days): void
    {
        BusinessHour::create(['weekday' => 2, 'opens_at' => '08:00', 'closes_at' => '12:00']);

        Livewire::actingAs($this->admin())->test(BusinessHoursPage::class)
            ->set('days', $days)
            ->call('saveHours')
            ->assertHasErrors();

        $this->assertSame(1, BusinessHour::query()->count());
    }

    public function test_adiciona_e_remove_faixa_na_tela_com_limite_de_quatro(): void
    {
        $component = Livewire::actingAs($this->admin())->test(BusinessHoursPage::class);

        foreach (range(1, 6) as $ignored) {
            $component->call('addWindow', 2);
        }

        $this->assertCount(4, $component->get('days')[2]);

        $component->call('removeWindow', 2, 0);
        $this->assertCount(3, $component->get('days')[2]);
    }

    public function test_cadastra_e_remove_feriado_com_data_unica(): void
    {
        $component = Livewire::actingAs($this->admin())->test(BusinessHoursPage::class)
            ->set('holidayDate', '2026-12-25')
            ->set('holidayName', 'Natal')
            ->call('addHoliday')
            ->assertHasNoErrors();

        $this->assertSame(1, Holiday::query()->count());

        $component->set('holidayDate', '2026-12-25')->set('holidayName', 'Outro')->call('addHoliday')->assertHasErrors('holidayDate');
        $component->set('holidayDate', '')->set('holidayName', '')->call('addHoliday')->assertHasErrors(['holidayDate', 'holidayName']);

        $component->call('removeHoliday', Holiday::query()->sole()->id);
        $this->assertSame(0, Holiday::query()->count());
    }

    public function test_salva_liga_desliga_e_texto_e_mostra_a_previa_preenchida(): void
    {
        BusinessHour::create(['weekday' => 1, 'opens_at' => '08:00', 'closes_at' => '18:00']);

        Livewire::actingAs($this->admin())->test(BusinessHoursPage::class)
            ->assertSee('segunda, das 8h às 18h')
            ->set('afterHoursEnabled', false)
            ->set('afterHoursMessage', 'Fechado. Atendemos {{horario}}.')
            ->call('saveMessage')
            ->assertHasNoErrors()
            ->assertSee('Fechado. Atendemos segunda, das 8h às 18h.');

        $this->assertFalse(Setting::get('after_hours_enabled'));
        $this->assertSame('Fechado. Atendemos {{horario}}.', Setting::get('after_hours_message'));
    }

    public function test_texto_em_branco_volta_ao_padrao_e_passa_de_mil_caracteres_e_recusado(): void
    {
        $component = Livewire::actingAs($this->admin())->test(BusinessHoursPage::class)
            ->set('afterHoursMessage', str_repeat('a', 1001))
            ->call('saveMessage')
            ->assertHasErrors('afterHoursMessage');

        $component->set('afterHoursMessage', '   ')->call('saveMessage')->assertHasNoErrors();

        $this->assertNull(Setting::get('after_hours_message'));
    }
}
