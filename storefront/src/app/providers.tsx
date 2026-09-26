import CssBaseline from '@mui/material/CssBaseline';
import { ThemeProvider } from '@mui/material/styles';
import { QueryClientProvider, type QueryClient } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { HelmetProvider } from 'react-helmet-async';
import { MiniCartProvider } from '@/features/cart';
import { SnackbarProvider } from '@/shared/ui/Snackbar';
import { HttpEventListeners } from './components/GlobalNotices';
import { theme } from './theme/theme';

export function AppProviders({ client, children, helmetContext }: { client: QueryClient; children: ReactNode; helmetContext?: object }) {
  return (
    <HelmetProvider context={helmetContext}>
      <QueryClientProvider client={client}>
        <ThemeProvider theme={theme}>
          <CssBaseline />
          <SnackbarProvider>
            <MiniCartProvider>
              <HttpEventListeners />
              {children}
            </MiniCartProvider>
          </SnackbarProvider>
        </ThemeProvider>
      </QueryClientProvider>
    </HelmetProvider>
  );
}
