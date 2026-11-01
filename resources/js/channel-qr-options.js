// Opções do QR de pareamento com a logo no centro (ver resources/js/channel-qr.js).
//
// Correção de erro H tolera até ~30% do QR coberto; a logo fica em no máximo 20% do lado e o resto segue o padrão
// preto sobre branco, que é o que o leitor do WhatsApp lê melhor.

export const LOGO_MAX_RATIO = 0.2;

export function qrOptions(value, size, logoUrl) {
    return {
        width: size,
        height: size,
        type: 'canvas',
        data: value,
        image: logoUrl,
        margin: 0,
        qrOptions: { errorCorrectionLevel: 'H' },
        imageOptions: { hideBackgroundDots: true, imageSize: LOGO_MAX_RATIO, margin: 4, crossOrigin: 'anonymous' },
        dotsOptions: { color: '#000000', type: 'square' },
        backgroundOptions: { color: '#ffffff' },
    };
}
