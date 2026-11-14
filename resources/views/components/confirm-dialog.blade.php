{{-- Diálogo único de confirmação (resources/js/confirm-dialog.js): qualquer elemento com data-confirm / data-confirm-label / data-confirm-tone o abre.
     Os dois botões de confirmar se alternam por tom (só um aparece); "Cancelar" fecha sem valor. --}}
<dialog id="confirm-dialog" aria-labelledby="confirm-dialog-title" aria-describedby="confirm-dialog-text" class="m-auto w-[calc(100vw-2rem)] max-w-md rounded-xl border border-line bg-surface p-5 text-ink shadow-xl backdrop:bg-black/40">
    <form method="dialog" class="space-y-4">
        <div class="space-y-1">
            <h2 id="confirm-dialog-title" data-confirm-title class="text-base font-semibold"></h2>
            <p id="confirm-dialog-text" data-confirm-text class="text-sm text-ink-3"></p>
        </div>

        <div class="flex justify-end gap-2">
            <flux:button type="submit" value="cancel" variant="subtle">Cancelar</flux:button>
            <flux:button type="submit" value="ok" variant="primary" data-confirm-ok="primary">Confirmar</flux:button>
            <flux:button type="submit" value="ok" variant="danger" data-confirm-ok="danger" hidden>Confirmar</flux:button>
        </div>
    </form>
</dialog>
