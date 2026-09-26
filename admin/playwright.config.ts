import { defineConfig, devices } from '@playwright/test';

// E2E do painel contra a stack REAL: Laravel (padrão :8001) + Vite do painel (:5174, proxy /api e /sanctum).
// Pré-requisitos do backend: banco semeado (`php artisan migrate:fresh --seed`), APP_ENV=local,
// PAYMENTS_DRIVER=sandbox (rota /api/v1/dev/payments/{uuid}/approve) e SHIPPING_POSTAL_LOOKUP=fake
// quando o ViaCEP não estiver acessível. Servidores já em execução são reaproveitados.
const BASE_URL = process.env.E2E_BASE_URL ?? 'http://localhost:5174';
const API_URL = process.env.E2E_API_URL ?? 'http://localhost:8001';
export const ADMIN_STATE = 'e2e/.auth/admin.json';
const API_PORT = new URL(API_URL).port || '8001';

export default defineConfig({
  testDir: './e2e',
  timeout: 120_000,
  expect: { timeout: 15_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['list']],
  use: {
    baseURL: BASE_URL,
    locale: 'pt-BR',
    timezoneId: 'America/Sao_Paulo',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    acceptDownloads: true,
    actionTimeout: 15_000,
    navigationTimeout: 20_000,
    launchOptions: process.env.E2E_CHROMIUM_PATH ? { executablePath: process.env.E2E_CHROMIUM_PATH } : {},
  },
  projects: [
    // Uma única sessão de super-admin reaproveitada (o login tem rate limit de 5/min por e-mail).
    { name: 'setup', testMatch: /auth\.setup\.ts/ },
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'], storageState: ADMIN_STATE },
      dependencies: ['setup'],
    },
  ],
  webServer: [
    {
      // --no-reload: sem ele o `artisan serve` descarta variáveis de ambiente (ex.: DB_DATABASE) no processo filho.
      command: `php artisan serve --port=${API_PORT} --no-reload`,
      cwd: '../backend',
      url: `${API_URL}/api/v1/settings/public`,
      reuseExistingServer: true,
      timeout: 60_000,
    },
    {
      command: 'npm run dev',
      url: `${BASE_URL}/admin/`,
      reuseExistingServer: true,
      timeout: 60_000,
      env: { VITE_API_PROXY_TARGET: API_URL },
    },
  ],
});
