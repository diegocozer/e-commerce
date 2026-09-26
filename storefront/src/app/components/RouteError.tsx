import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Typography from '@mui/material/Typography';
import { useRouteError } from 'react-router';

/** ErrorBoundary por rota (ARCHITECTURE §8.5) — inclui falha ao baixar chunk. */
export function RouteError() {
  const error = useRouteError();
  if (import.meta.env.DEV) console.error(error);
  return (
    <Box role="alert" sx={{ textAlign: 'center', py: 12, px: 4 }}>
      <Typography variant="h2" component="h1" sx={{ mb: 2 }}>Algo deu errado</Typography>
      <Typography color="text.secondary" sx={{ mb: 4 }}>Não foi possível exibir esta página. Tente recarregar.</Typography>
      <Button onClick={() => window.location.reload()}>Recarregar página</Button>
    </Box>
  );
}
