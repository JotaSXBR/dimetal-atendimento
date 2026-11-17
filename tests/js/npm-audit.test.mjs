import assert from 'node:assert/strict';
import test from 'node:test';
import { blockingAdvisories } from '../../.github/scripts/npm-audit.mjs';

const advisory = (ghsa, severity, name = 'pkg') => ({
    source: 1,
    name,
    title: `aviso ${ghsa}`,
    url: `https://github.com/advisories/${ghsa}`,
    severity,
    range: '<=1.0.0',
});

// Formato do `npm audit --json` (v2): o aviso de verdade fica no `via` do pacote afetado; os pacotes que só dependem
// dele trazem o nome no `via`.
const report = (...advisories) => ({
    vulnerabilities: {
        pkg: { name: 'pkg', severity: 'high', via: advisories },
        parent: { name: 'parent', severity: 'high', via: ['pkg'] },
    },
});

test('aviso alto fora da lista bloqueia', () => {
    const blocking = blockingAdvisories(report(advisory('GHSA-aaaa-bbbb-cccc', 'high')), []);
    assert.deepEqual(blocking.map((a) => a.ghsa), ['GHSA-aaaa-bbbb-cccc']);
});

test('aviso crítico fora da lista bloqueia', () => {
    assert.equal(blockingAdvisories(report(advisory('GHSA-aaaa-bbbb-cccc', 'critical')), []).length, 1);
});

test('aviso liberado não bloqueia, nem nos pacotes que dependem dele', () => {
    assert.deepEqual(blockingAdvisories(report(advisory('GHSA-aaaa-bbbb-cccc', 'high')), ['GHSA-aaaa-bbbb-cccc']), []);
});

test('liberar um aviso não libera outro do mesmo pacote', () => {
    const blocking = blockingAdvisories(
        report(advisory('GHSA-aaaa-bbbb-cccc', 'high'), advisory('GHSA-dddd-eeee-ffff', 'high')),
        ['GHSA-aaaa-bbbb-cccc'],
    );
    assert.deepEqual(blocking.map((a) => a.ghsa), ['GHSA-dddd-eeee-ffff']);
});

test('moderado e baixo não bloqueiam, como no --audit-level=high', () => {
    assert.deepEqual(blockingAdvisories(report(advisory('GHSA-aaaa-bbbb-cccc', 'moderate'), advisory('GHSA-dddd-eeee-ffff', 'low')), []), []);
});

test('o mesmo aviso em dois pacotes aparece uma vez', () => {
    const r = {
        vulnerabilities: {
            a: { severity: 'high', via: [advisory('GHSA-aaaa-bbbb-cccc', 'high', 'a')] },
            b: { severity: 'high', via: [advisory('GHSA-aaaa-bbbb-cccc', 'high', 'b')] },
        },
    };
    assert.equal(blockingAdvisories(r, []).length, 1);
});

test('relatório sem o formato esperado falha em vez de passar', () => {
    assert.throws(() => blockingAdvisories({ error: { code: 'ENOLOCK' } }, []));
});
