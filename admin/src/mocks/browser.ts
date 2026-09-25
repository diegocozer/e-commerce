import { setupWorker } from 'msw/browser';
import { handlers } from './handlers';

/** Mocks no navegador (VITE_USE_MOCKS=true): painel funciona sem backend. */
export async function startMockWorker(): Promise<void> {
  const worker = setupWorker(...handlers);
  await worker.start({
    serviceWorker: { url: `${import.meta.env.BASE_URL}mockServiceWorker.js` },
    onUnhandledRequest: 'bypass',
  });
}
