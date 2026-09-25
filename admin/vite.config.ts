/// <reference types="vitest/config" />
import { fileURLToPath } from 'node:url';
import react from '@vitejs/plugin-react';
import { defineConfig, loadEnv } from 'vite';

// Painel servido em /admin (ARCHITECTURE §8.10). Em dev, /api e /sanctum vão para o Laravel.
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '');
  const target = env.VITE_API_PROXY_TARGET || 'http://localhost:8000';
  return {
    base: '/admin/',
    plugins: [react()],
    resolve: { alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) } },
    server: {
      port: 5174,
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
            if (id.includes('recharts') || id.includes('d3-')) return 'charts';
            if (id.includes('@mui/icons-material')) return 'mui-icons';
            if (id.includes('@mui') || id.includes('@emotion')) return 'mui';
            if (id.includes('@tanstack')) return 'tanstack';
            if (id.includes('react-hook-form') || id.includes('zod') || id.includes('@hookform')) return 'forms';
            if (id.includes('msw')) return 'msw';
            if (id.includes('react')) return 'react';
            return undefined;
          },
        },
      },
    },
    test: {
      environment: 'jsdom',
      globals: false,
      setupFiles: ['./src/test/setup.ts'],
      css: false,
      include: ['src/**/*.test.{ts,tsx}'],
      testTimeout: 20000,
    },
  };
});
