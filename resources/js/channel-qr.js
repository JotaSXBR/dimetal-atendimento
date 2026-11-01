// QR de pareamento do cartão de Canais, desenhado no navegador com a logo da empresa no centro.
//
// O servidor entrega o texto cru do QR; a biblioteca só é baixada quando há um QR para mostrar. Se o desenho falhar, o
// cartão volta à imagem do WAHA (`qrFallback`), então conectar um número nunca depende deste arquivo.

import { qrOptions } from './channel-qr-options.js';

const SIZE = 224;

document.addEventListener('alpine:init', () => {
    window.Alpine.data('channelQr', ($wire, value, logoUrl) => ({
        async init() {
            try {
                const { default: QRCodeStyling } = await import('qr-code-styling');

                this.$refs.canvas.replaceChildren();
                new QRCodeStyling(qrOptions(value, SIZE, logoUrl)).append(this.$refs.canvas);
            } catch {
                $wire.qrFallback();
            }
        },
    }));
});
