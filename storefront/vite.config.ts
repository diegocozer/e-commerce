/// <reference types="vitest/config" />
import { fileURLToPath, URL } from 'node:url';
import react from '@vitejs/plugin-react';
import { defineConfig, loadEnv } from 'vite';
import { configDefaults } from 'vitest/config';

// Dev: proxy /api e /sanctum para o Laravel (ARCHITECTURE §8.10). changeOrigin=false
// preserva Host/Origin para o Sanctum reconhecer a SPA como stateful.
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '');
  const target = env.VITE_API_PROXY_TARGET || 'http://localhost:8000';
  return {
    plugins: [react()],
    resolve: {
      alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
    },
    server: {
      port: 5173,
      strictPort: true,
      proxy: {
        '/api': { target, changeOrigin: false },
        '/sanctum': { target, changeOrigin: false },
      },
    },
    build: {
      rollupOptions: {
        output: {
          manualChunks(id: string) {
            if (!id.includes('node_modules')) return undefined;
            if (id.includes('@mui/icons-material')) return 'mui-icons';
            if (id.includes('@mui') || id.includes('@emotion')) return 'mui';
            if (id.includes('@tanstack')) return 'query';
            if (id.includes('react-router') || id.includes('/react-dom/') || id.includes('/react/') || id.includes('scheduler')) return 'react';
            return undefined;
          },
        },
      },
    },
    test: {
      globals: true,
      environment: 'jsdom',
      setupFiles: ['./src/test/setup.ts'],
      // e2e/ é Playwright (npm run e2e), não Vitest.
      exclude: [...configDefaults.exclude, 'e2e/**'],
      css: false,
      restoreMocks: true,
      testTimeout: 15000,
    },
  };
});
