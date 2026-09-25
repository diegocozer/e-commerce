export const env = {
  useMocks: import.meta.env.VITE_USE_MOCKS === 'true',
  isDev: import.meta.env.DEV,
  storeName: 'Comunika Suprimentos',
  siteUrl: (import.meta.env.VITE_SITE_URL as string | undefined) ?? '',
} as const;
