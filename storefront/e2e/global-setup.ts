import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

// Rodadas seguidas da suíte estouram os limites por IP (ex.: `register` 20/h) —
// limpa o cache do backend local (onde ficam os contadores do RateLimiter).
// Desligue com E2E_SKIP_CACHE_CLEAR=1 quando o backend não for local.
export default function globalSetup(): void {
  if (process.env.E2E_SKIP_CACHE_CLEAR === '1') return;
  const backend = fileURLToPath(new URL('../../backend', import.meta.url));
  execFileSync('php', ['artisan', 'cache:clear'], { cwd: backend, stdio: 'ignore' });
}
