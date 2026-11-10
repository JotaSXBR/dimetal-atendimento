<?php

namespace App\Livewire\Settings;

use App\Actions\Sectors\DeleteSector;
use App\Actions\Sectors\SectorInUse;
use App\Enums\SectorStatus;
use App\Models\Sector;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Os setores: criar, renomear, apagar (sem uso), estado e ordem no menu. O padrão (Vendas, Financeiro...) é instalado uma vez
 * por `DefaultSectors` e daí em diante é do admin.
 */
#[Title('Setores')]
class Sectors extends Component
{
    public const NAME_LIMIT = 60;

    public string $newName = '';

    public function mount(): void
    {
        $this->authorize('admin');
    }

    public function create(string $name): void
    {
        $this->authorize('admin');
        $this->resetErrorBag();

        $name = trim($name);

        if (($error = $this->nameError($name, null)) !== null) {
            $this->addError('newName', $error);

            return;
        }

        $last = Sector::query()->max('menu_order');

        Sector::query()->create([
            'name' => $name,
            'menu_order' => (is_numeric($last) ? (int) $last : 0) + 1,
            'status' => SectorStatus::ATIVO,
        ]);

        $this->newName = '';
    }

    public function rename(int $id, string $name): void
    {
        $this->authorize('admin');
        $this->resetErrorBag();

        $sector = Sector::query()->findOrFail($id);
        $name = trim($name);

        if (($error = $this->nameError($name, $sector->id)) !== null) {
            $this->addError('rename.'.$id, $error);

            return;
        }

        $sector->update(['name' => $name]);
    }

    public function remove(int $id, DeleteSector $delete): void
    {
        $this->authorize('admin');
        $this->resetErrorBag();

        try {
            $delete(Sector::query()->findOrFail($id));
        } catch (SectorInUse $e) {
            $this->addError('remove.'.$id, $e->getMessage());
        }
    }

    public function setStatus(int $id, string $status): void
    {
        $this->authorize('admin');
        $this->resetErrorBag();

        $sector = Sector::query()->findOrFail($id);
        $new = SectorStatus::tryFrom($status);

        if ($new === null) {
            $this->addError('status.'.$id, 'Estado inválido.');

            return;
        }

        if ($new === SectorStatus::DESATIVADO && $sector->hasOpenTickets()) {
            $this->addError('status.'.$id, 'Há tickets abertos neste setor. Transfira ou encerre antes de desativá-lo.');

            return;
        }

        $sector->update(['status' => $new]);
    }

    public function move(int $id, string $direction): void
    {
        $this->authorize('admin');

        DB::transaction(function () use ($id, $direction): void {
            $ordered = Sector::query()->orderBy('menu_order')->orderBy('id')->lockForUpdate()->get()->values();
            $index = $ordered->search(fn (Sector $sector): bool => $sector->id === $id);

            if ($index === false) {
                return;
            }

            $target = $direction === 'up' ? $index - 1 : $index + 1;

            if ($target < 0 || $target >= $ordered->count()) {
                return;
            }

            $ids = $ordered->map(fn (Sector $sector): int => $sector->id)->all();
            [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];

            // Renumera de 1 a n: a sequência fica contínua mesmo que tenha nascido com saltos.
            foreach ($ids as $position => $sectorId) {
                Sector::query()->whereKey($sectorId)->update(['menu_order' => $position + 1]);
            }
        });
    }

    /** Mensagem de erro do nome (vazio, longo ou repetido entre os setores não apagados) ou `null`. */
    private function nameError(string $name, ?int $ignoreId): ?string
    {
        return Validator::make(['name' => $name], [
            'name' => ['required', 'string', 'max:'.self::NAME_LIMIT, Rule::unique('sectors', 'name')->whereNull('deleted_at')->ignore($ignoreId)],
        ], [
            'name.required' => 'Informe o nome do setor.',
            'name.max' => 'O nome pode ter até '.self::NAME_LIMIT.' caracteres.',
            'name.unique' => 'Já existe um setor com esse nome.',
        ])->errors()->first('name') ?: null;
    }

    public function render(): View
    {
        return view('livewire.settings.sectors', [
            'sectors' => Sector::query()->orderBy('menu_order')->orderBy('id')->get(),
            'statuses' => SectorStatus::cases(),
        ]);
    }
}
