import assert from 'node:assert/strict';
import test from 'node:test';
import { token, variableChoices } from '../../resources/js/flow-editor/variables.js';

const builtin = [
    { key: 'nome', label: 'Nome do contato' },
    { key: 'empresa', label: 'Nome da empresa' },
];

test('variableChoices: embutidas primeiro e depois as respostas das perguntas, na ordem do grafo', () => {
    const nodes = [
        { type: 'pergunta', data: { variavel: 'cpf' } },
        { type: 'mensagem', data: { texto: 'oi' } },
        { type: 'pergunta', data: { variavel: 'cep' } },
    ];

    assert.deepEqual(variableChoices(builtin, nodes).map((v) => v.key), ['nome', 'empresa', 'cpf', 'cep']);
    assert.equal(variableChoices(builtin, nodes)[2].label, 'Resposta: cpf');
});

test('variableChoices: ignora vazias, repetidas e as que repetem uma embutida', () => {
    const nodes = [
        { type: 'pergunta', data: { variavel: '  cpf ' } },
        { type: 'pergunta', data: { variavel: 'cpf' } },
        { type: 'pergunta', data: { variavel: '   ' } },
        { type: 'pergunta', data: {} },
        { type: 'pergunta', data: { variavel: 'nome' } },
    ];

    assert.deepEqual(variableChoices(builtin, nodes).map((v) => v.key), ['nome', 'empresa', 'cpf']);
});

test('token: envolve a chave em chaves duplas', () => {
    assert.equal(token('nome'), '{{nome}}');
});

test('variableChoices: só oferece nomes que o bot resolve (minúsculas, números e _, sem começar por _)', () => {
    const nodes = ['Meu CPF', 'cpf do cliente', 'endereço', 'CPF', '_interna', 'cpf', 'cep_2'].map((variavel) => ({ type: 'pergunta', data: { variavel } }));

    assert.deepEqual(variableChoices(builtin, nodes).map((v) => v.key), ['nome', 'empresa', 'cpf', 'cep_2']);
});
