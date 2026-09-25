import { Box, Button, Typography } from '@mui/material';
import { isRouteErrorResponse, useRouteError } from 'react-router-dom';
import { NotFoundPage } from './NotFoundPage';

/** ErrorBoundary por rota (inclui falha ao carregar chunk). */
export function RouteError() {
  const error = useRouteError();
  if (isRouteErrorResponse(error) && error.status === 404) return <NotFoundPage />;
  return (
    <Box sx={{ textAlign: 'center', py: 16 }} role="alert">
      <Typography variant="h1">Algo deu errado</Typography>
      <Typography color="text.secondary" sx={{ mt: 2 }}>
        Não foi possível exibir esta página. Recarregue para tentar novamente.
      </Typography>
      <Button sx={{ mt: 4 }} variant="contained" onClick={() => window.location.reload()}>
        Recarregar
      </Button>
    </Box>
  );
}
