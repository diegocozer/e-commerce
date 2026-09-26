import { defineConfig, devices } from '@playwright/test';

// E2E contra a stack REAL (Laravel :8000 + Vite :5173 com proxy /api e /sanctum).
// Pré-requisitos do backend: banco semeado (`php artisan migrate:fresh --seed`),
// PAYMENTS_DRIVER=sandbox, APP_ENV=local (rota /api/v1/dev/payments/{uuid}/approve)
// e SHIPPING_POSTAL_LOOKUP=fake quando o ViaCEP não estiver acessível.
// Os servidores são reaproveitados se já estiverem rodando.
const BASE_URL = process.env.E2E_BASE_URL ?? 'http://localhost:5173';
const API_URL = process.env.E2E_API_URL ?? 'http://localhost:8000';

export default defineConfig({
  testDir: './e2e',
  timeout: 90_000,
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
    launchOptions: process.env.E2E_CHROMIUM_PATH ? { executablePath: process.env.E2E_CHROMIUM_PATH } : {},
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
  webServer: [
    {
      command: 'php artisan serve --port=8000',
      cwd: '../backend',
      url: `${API_URL}/api/v1/settings/public`,
      reuseExistingServer: true,
      timeout: 60_000,
    },
    {
      command: 'npm run dev',
      url: BASE_URL,
      reuseExistingServer: true,
      timeout: 60_000,
    },
  ],
});
