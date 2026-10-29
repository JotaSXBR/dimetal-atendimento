// Bandeja de anexos do inbox (ver resources/views/livewire/inbox/inbox.blade.php).
//
// Cada arquivo sobe numa requisição própria e só entra na bandeja depois que o servidor o aceita: um arquivo recusado ou
// grande demais não derruba os outros, e o corpo de cada requisição nunca passa dos 16 MB. A mesma entrada serve ao clipe,
// ao Ctrl+V de imagem e ao arrastar para a conversa.

const MAX_BYTES = 16 * 1024 * 1024;

document.addEventListener('alpine:init', () => {
    window.Alpine.data('attachTray', ($wire) => ({
        uploading: 0,
        dragging: false,
        voiceBusy: false,
        notice: '',

        async addFiles(list) {
            this.notice = '';

            for (const file of Array.from(list ?? [])) {
                if (file.size > MAX_BYTES) {
                    this.notice = `O arquivo ${file.name} passa de 16 MB.`;

                    continue;
                }

                this.uploading += 1;

                try {
                    await new Promise((resolve) => {
                        $wire.$upload('upload', file, () => resolve($wire.commitUpload()), () => resolve(), () => {});
                    });
                } finally {
                    this.uploading -= 1;
                }
            }
        },

        paste(event) {
            const files = event.clipboardData?.files;

            if (files && files.length > 0) {
                event.preventDefault();
                this.addFiles(files);
            }
        },

        drop(event) {
            this.dragging = false;
            this.addFiles(event.dataTransfer?.files);
        },
    }));
});
