// Editor de enquadramento da foto (usuário, logo): escolher a imagem, arrastar e aproximar, "Usar esta foto".
//
// O Cropper.js só é baixado quando alguém escolhe um arquivo. O navegador recorta o quadrado e reduz para OUTPUT px, então só esse
// recorte (nunca o original) sobe pelo Livewire; o servidor revalida e converte (App\Services\Media\AvatarImage).
// A imagem nasce cobrindo o quadrado, centralizada: sem mexer em nada, o resultado já é o recorte central.

import { shown } from './shown.js';

const OUTPUT = 512;
const ZOOM_STEP = 0.1;
const NUDGE_PX = 12;
const TYPES = /^image\/(png|jpe?g|webp)$/;

document.addEventListener('alpine:init', () => {
    window.Alpine.data('avatarCropper', (model, action) => ({
        open: false,
        busy: false,
        error: '',
        url: null,

        async pick(event) {
            const file = event.target.files?.[0];
            event.target.value = '';

            if (!file) {
                return;
            }

            if (!TYPES.test(file.type)) {
                this.error = 'Use uma imagem PNG, JPG ou WebP.';

                return;
            }

            try {
                await import('cropperjs');
            } catch {
                this.error = 'Não foi possível abrir o editor. Recarregue a página e tente de novo.';

                return;
            }

            this.error = '';
            this.url = URL.createObjectURL(file);
            this.open = true;
            await this.$nextTick();
            await shown(this.$refs.stage);

            this.$refs.stage.innerHTML = `
                <cropper-canvas style="width: 100%; height: 100%;">
                    <cropper-image src="${this.url}" alt="" initial-center-size="cover" translatable scalable></cropper-image>
                    <cropper-shade hidden></cropper-shade>
                    <cropper-handle action="move" plain></cropper-handle>
                    <cropper-selection aspect-ratio="1" initial-coverage="1" outlined>
                        <cropper-grid role="presentation" covered></cropper-grid>
                    </cropper-selection>
                </cropper-canvas>`;
            this.$refs.stage.focus();
        },

        image() {
            return this.$refs.stage.querySelector('cropper-image');
        },

        zoom(direction) {
            const image = this.image();

            if (!image) {
                return;
            }

            image.$zoom(direction * ZOOM_STEP);

            // Afastar além do quadrado deixaria borda vazia: volta ao enquadramento que cobre tudo.
            const stage = this.$refs.stage.getBoundingClientRect();
            const box = image.getBoundingClientRect();

            if (box.width < stage.width - 1 || box.height < stage.height - 1) {
                image.$center('cover');
            }
        },

        move(x, y) {
            this.image()?.$move(x, y);
        },

        nudge() {
            return NUDGE_PX;
        },

        close() {
            this.open = false;
            this.error = '';

            if (this.url) {
                URL.revokeObjectURL(this.url);
                this.url = null;
            }

            this.$refs.stage.innerHTML = '';
        },

        async confirm() {
            const selection = this.$refs.stage.querySelector('cropper-selection');

            if (!selection || this.busy) {
                return;
            }

            this.busy = true;
            this.error = '';

            try {
                const canvas = await selection.$toCanvas({
                    width: OUTPUT,
                    height: OUTPUT,
                    beforeDraw(context) {
                        context.fillStyle = '#ffffff';
                        context.fillRect(0, 0, OUTPUT, OUTPUT);
                    },
                });
                const blob = await new Promise((resolve) => canvas.toBlob((b) => (b ? resolve(b) : canvas.toBlob(resolve, 'image/png')), 'image/webp', 0.9));

                this.$wire.upload(
                    model,
                    new File([blob], 'avatar.' + (blob.type === 'image/png' ? 'png' : 'webp'), { type: blob.type }),
                    async () => {
                        await this.$wire.call(action);
                        this.busy = false;
                        this.close();
                    },
                    () => {
                        this.busy = false;
                        this.error = 'Não foi possível enviar a imagem. Tente de novo.';
                    },
                );
            } catch {
                this.busy = false;
                this.error = 'Não foi possível preparar a imagem. Tente outra.';
            }
        },
    }));
});
