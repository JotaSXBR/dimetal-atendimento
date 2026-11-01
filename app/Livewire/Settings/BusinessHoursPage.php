<?php

namespace App\Livewire\Settings;

use App\Models\BusinessHour;
use App\Models\Holiday;
use App\Models\Setting;
use App\Services\Inbox\AfterHoursReply;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Horário e SLA')]
class BusinessHoursPage extends Component
{
    private const MAX_WINDOWS_PER_DAY = 4;

    /** Segunda a domingo, como a grade é exibida (0 = domingo no banco). */
    public const WEEKDAYS = [1 => 'Segunda-feira', 2 => 'Terça-feira', 3 => 'Quarta-feira', 4 => 'Quinta-feira', 5 => 'Sexta-feira', 6 => 'Sábado', 0 => 'Domingo'];

    /** @var array<int, array<int, array{opens: string, closes: string}>> */
    public array $days = [];

    public string $holidayDate = '';

    public string $holidayName = '';

    public bool $afterHoursEnabled = true;

    public string $afterHoursMessage = '';

    public function mount(): void
    {
        foreach (BusinessHour::query()->orderBy('opens_at')->get() as $window) {
            $this->days[$window->weekday][] = [
                'opens' => substr($window->opens_at, 0, 5),
                'closes' => substr($window->closes_at, 0, 5),
            ];
        }

        $this->afterHoursEnabled = (bool) Setting::get('after_hours_enabled', true);
        $message = Setting::get('after_hours_message');
        $this->afterHoursMessage = is_string($message) ? $message : '';
    }

    public function addWindow(int $weekday): void
    {
        if (! array_key_exists($weekday, self::WEEKDAYS) || count($this->days[$weekday] ?? []) >= self::MAX_WINDOWS_PER_DAY) {
            return;
        }

        $this->days[$weekday][] = ['opens' => '08:00', 'closes' => '12:00'];
    }

    public function removeWindow(int $weekday, int $index): void
    {
        unset($this->days[$weekday][$index]);
        $this->days[$weekday] = array_values($this->days[$weekday] ?? []);

        if ($this->days[$weekday] === []) {
            unset($this->days[$weekday]);
        }
    }

    public function saveHours(): void
    {
        $this->resetErrorBag();

        // A propriedade é pública e vem do navegador: a forma do array é conferida no servidor antes de qualquer uso.
        $time = ['required', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'];
        $this->validate([
            'days' => ['array'],
            'days.*' => ['array', 'max:'.self::MAX_WINDOWS_PER_DAY],
            'days.*.*.opens' => $time,
            'days.*.*.closes' => $time,
        ], [
            'days.*.max' => 'No máximo '.self::MAX_WINDOWS_PER_DAY.' faixas por dia.',
            'days.*.*.opens.*' => 'Use horários no formato HH:MM.',
            'days.*.*.closes.*' => 'Use horários no formato HH:MM.',
        ]);

        $rows = [];

        foreach ($this->days as $weekday => $windows) {
            $this->assertDay((int) $weekday, array_values($windows));

            foreach ($windows as $window) {
                $rows[] = ['weekday' => (int) $weekday, 'opens_at' => $window['opens'], 'closes_at' => $window['closes']];
            }
        }

        DB::transaction(function () use ($rows): void {
            BusinessHour::query()->delete();

            foreach ($rows as $row) {
                BusinessHour::create($row);
            }
        });

        session()->flash('saved', 'Horário salvo.');
    }

    public function addHoliday(): void
    {
        $this->validate([
            'holidayDate' => ['required', 'date_format:Y-m-d', 'unique:holidays,date'],
            'holidayName' => ['required', 'string', 'max:120'],
        ], [
            'holidayDate.required' => 'Informe a data do feriado.',
            'holidayDate.date_format' => 'Data inválida.',
            'holidayDate.unique' => 'Essa data já está cadastrada.',
            'holidayName.required' => 'Informe o nome do feriado.',
            'holidayName.max' => 'O nome passa de 120 caracteres.',
        ]);

        Holiday::create(['date' => $this->holidayDate, 'name' => trim($this->holidayName)]);

        $this->reset('holidayDate', 'holidayName');
    }

    public function removeHoliday(int $id): void
    {
        Holiday::query()->whereKey($id)->delete();
    }

    public function saveMessage(): void
    {
        $this->validate(['afterHoursMessage' => ['nullable', 'string', 'max:1000']], [
            'afterHoursMessage.max' => 'O texto passa de 1000 caracteres.',
        ]);

        $message = trim($this->afterHoursMessage);

        Setting::put('after_hours_enabled', $this->afterHoursEnabled, 'boolean');
        Setting::put('after_hours_message', $message === '' ? null : $message);
        $this->afterHoursMessage = $message;

        session()->flash('saved-message', 'Resposta automática salva.');
    }

    /**
     * @param  array<int, array{opens: string, closes: string}>  $windows
     */
    private function assertDay(int $weekday, array $windows): void
    {
        $field = 'days.'.$weekday;

        if (! array_key_exists($weekday, self::WEEKDAYS)) {
            throw ValidationException::withMessages([$field => 'Dia da semana inválido.']);
        }

        $previousClose = null;

        usort($windows, fn (array $a, array $b): int => strcmp($a['opens'], $b['opens']));

        foreach ($windows as $window) {
            $opens = $window['opens'];
            $closes = $window['closes'];

            if ($opens >= $closes) {
                throw ValidationException::withMessages([$field => 'A faixa precisa abrir antes de fechar.']);
            }

            if ($previousClose !== null && $opens < $previousClose) {
                throw ValidationException::withMessages([$field => 'As faixas do mesmo dia não podem se sobrepor.']);
            }

            $previousClose = $closes;
        }
    }

    public function render(): View
    {
        return view('livewire.settings.business-hours-page', [
            'holidays' => Holiday::query()->orderBy('date')->get(),
            'preview' => app(AfterHoursReply::class)->render($this->afterHoursMessage, now()),
        ]);
    }
}
