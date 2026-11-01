import assert from 'node:assert/strict';
import test from 'node:test';
import { LOGO_MAX_RATIO, qrOptions } from '../../resources/js/channel-qr-options.js';

test('usa correção de erro H, que tolera a logo cobrindo o centro', () => {
    assert.equal(qrOptions('abc', 224, '/logo.svg').qrOptions.errorCorrectionLevel, 'H');
});

test('a logo cobre no máximo 20% do lado e nunca é zero', () => {
    const { imageSize } = qrOptions('abc', 224, '/logo.svg').imageOptions;

    assert.ok(imageSize > 0);
    assert.ok(imageSize <= LOGO_MAX_RATIO && LOGO_MAX_RATIO <= 0.2);
});

test('o QR leva o texto do WAHA sem mexer nele e a logo recebida', () => {
    const options = qrOptions('ref,chave,segredo', 224, '/images/qr-logo.svg');

    assert.equal(options.data, 'ref,chave,segredo');
    assert.equal(options.image, '/images/qr-logo.svg');
    assert.equal(options.width, 224);
});

test('preto sobre branco, para não atrapalhar a leitura', () => {
    const options = qrOptions('abc', 224, '/logo.svg');

    assert.equal(options.dotsOptions.color, '#000000');
    assert.equal(options.backgroundOptions.color, '#ffffff');
});
