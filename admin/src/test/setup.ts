import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { afterAll, afterEach, beforeAll } from 'vitest';
import { resetDb } from '@/mocks/db';
import { server } from '@/mocks/server';
import { resetNotifications } from '@/shared/ui/notify';

// jsdom não implementa matchMedia/ResizeObserver/scrollTo (MUI color scheme, recharts).
if (!window.matchMedia) {
  window.matchMedia = (query: string) =>
    ({ matches: false, media: query, onchange: null, addListener: () => {}, removeListener: () => {}, addEventListener: () => {}, removeEventListener: () => {}, dispatchEvent: () => false }) as MediaQueryList;
}
if (!('ResizeObserver' in window)) {
  (window as unknown as { ResizeObserver: unknown }).ResizeObserver = class {
    observe() {}
    unobserve() {}
    disconnect() {}
  };
}
window.scrollTo = () => {};

beforeAll(() => server.listen({ onUnhandledRequest: 'error' }));
afterEach(() => {
  cleanup();
  server.resetHandlers();
  resetDb();
  resetNotifications();
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
});
afterAll(() => server.close());
