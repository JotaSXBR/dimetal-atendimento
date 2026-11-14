import assert from 'node:assert/strict';
import test from 'node:test';
import { placeMenu } from '../../resources/js/menu-placement.js';

const VIEW = { viewportWidth: 1000, viewportHeight: 800 };
const MENU = { menuWidth: 150, menuHeight: 100 };

test('placeMenu: com espaço abaixo, abre abaixo do botão e alinhado à direita dele', () => {
    const r = placeMenu({ button: { top: 100, bottom: 120, right: 500 }, ...MENU, area: { top: 0, bottom: 700 }, ...VIEW });

    assert.deepEqual(r, { top: 124, left: 350, above: false });
});

test('placeMenu: perto do rodapé da área, abre acima do botão', () => {
    const r = placeMenu({ button: { top: 640, bottom: 660, right: 500 }, ...MENU, area: { top: 0, bottom: 700 }, ...VIEW });

    assert.deepEqual(r, { top: 536, left: 350, above: true });
});

test('placeMenu: sem área usa a viewport como limite', () => {
    const r = placeMenu({ button: { top: 760, bottom: 780, right: 500 }, ...MENU, area: null, ...VIEW });

    assert.equal(r.above, true);
    assert.equal(r.top, 656);
});

test('placeMenu: sem espaço à esquerda o menu fica a uma margem da borda', () => {
    const r = placeMenu({ button: { top: 100, bottom: 120, right: 60 }, ...MENU, area: { top: 0, bottom: 700 }, ...VIEW });

    assert.equal(r.left, 8);
});

test('placeMenu: sem espaço à direita o menu não passa da viewport', () => {
    const r = placeMenu({ button: { top: 100, bottom: 120, right: 1000 }, ...MENU, area: { top: 0, bottom: 700 }, ...VIEW });

    assert.equal(r.left, 842);
});

test('placeMenu: menu mais alto que a área nunca sobe acima do teto', () => {
    const r = placeMenu({ button: { top: 100, bottom: 120, right: 500 }, menuWidth: 150, menuHeight: 900, area: { top: 50, bottom: 700 }, ...VIEW });

    assert.equal(r.top, 58);
});
