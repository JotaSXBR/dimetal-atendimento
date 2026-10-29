<?php

namespace App\Livewire\Settings;

use App\Enums\SectorStatus;
use App\Enums\TicketStatus;
use App\Models\Sector;
use App\Models\Ticket;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Estado e ordem no menu dos setores. Os cinco setores são fixos na v1: criar, renomear e apagar ficam para depois.
 */
#[Title('Setores')]
class Sectors extends Component
{
    public function mount(): void
    {
        $this->authorize('admin');
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

        if ($new === SectorStatus::DESATIVADO && $this->hasOpenTickets($sector)) {
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

    private function hasOpenTickets(Sector $sector): bool
    {
        return Ticket::query()
            ->where('sector_id', $sector->id)
            ->where('status', '<>', TicketStatus::ENCERRADO->value)
            ->exists();
    }

    public function render(): View
    {
        return view('livewire.settings.sectors', [
            'sectors' => Sector::query()->orderBy('menu_order')->orderBy('id')->get(),
            'statuses' => SectorStatus::cases(),
        ]);
    }
}
