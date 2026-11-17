// npm audit pelo lockfile, reprovando em aviso alto ou crítico (o mesmo que --audit-level=high), menos os GHSAs de
// ALLOWED. O npm audit não tem como ignorar um aviso, por isso este filtro. Uso: node .github/scripts/npm-audit.mjs
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

// Cada entrada: motivo e a data em que foi conferida. Tirar assim que houver versão corrigida e o Dependabot subir.
const ALLOWED = [
    // picomatch <= 4.0.7, ReDoS em extglob repetido. Sem versão corrigida em 17/11/2026 (a 4.0.7 é a última no npm e
    // no GitHub). Entra só por ferramentas de build (vite, fontaine, laravel-vite-plugin), que leem globs do próprio
    // projeto, não da entrada de usuário.
    'GHSA-fc5q-39xc-vjpf',
];

const BLOCKING = new Set(['high', 'critical']);

export function blockingAdvisories(report, allowed) {
    if (!report || typeof report.vulnerabilities !== 'object' || report.vulnerabilities === null) {
        throw new Error(`relatório do npm audit sem "vulnerabilities": ${JSON.stringify(report).slice(0, 300)}`);
    }
    const found = new Map();
    for (const vuln of Object.values(report.vulnerabilities)) {
        for (const via of vuln.via ?? []) {
            if (typeof via !== 'object' || !BLOCKING.has(via.severity)) {
                continue;
            }
            const ghsa = String(via.url ?? '').split('/').pop();
            if (!allowed.includes(ghsa) && !found.has(ghsa)) {
                found.set(ghsa, { ghsa, name: via.name, severity: via.severity, title: via.title });
            }
        }
    }
    return [...found.values()];
}

function audit() {
    try {
        return execFileSync('npm', ['audit', '--package-lock-only', '--json'], { encoding: 'utf8', shell: process.platform === 'win32' });
    } catch (error) {
        // npm audit sai com 1 quando acha qualquer aviso; o JSON continua na saída e é ele que decide.
        if (typeof error.stdout === 'string' && error.stdout.trim() !== '') {
            return error.stdout;
        }
        throw error;
    }
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
    const blocking = blockingAdvisories(JSON.parse(audit()), ALLOWED);
    for (const a of blocking) {
        console.error(`${a.severity}: ${a.name} ${a.ghsa} ${a.title}`);
    }
    console.log(blocking.length === 0
        ? `npm audit: nenhum aviso alto ou crítico (liberados: ${ALLOWED.join(', ') || 'nenhum'})`
        : `npm audit: ${blocking.length} aviso(s) alto(s) ou crítico(s)`);
    process.exit(blocking.length === 0 ? 0 : 1);
}
