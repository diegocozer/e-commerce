import { useState } from 'react';
import { RouterProvider } from 'react-router';
import { AppProviders } from './providers';
import { createQueryClient } from './queryClient';
import { createAppRouter } from './router';

export function App() {
  const [client] = useState(createQueryClient);
  const [router] = useState(createAppRouter);
  return (
    <AppProviders client={client}>
      <RouterProvider router={router} />
    </AppProviders>
  );
}
