import { Box, Button, Typography } from '@mui/material';
import { Link } from 'react-router-dom';
import { usePageTitle } from '@/shared/hooks/usePageTitle';

export function NotFoundPage() {
  usePageTitle('Página não encontrada');
  return (
    <Box sx={{ textAlign: 'center', py: 16 }}>
      <Typography variant="h1">Página não encontrada</Typography>
      <Typography color="text.secondary" sx={{ mt: 2 }}>
        O endereço pode ter mudado ou o registro foi excluído.
      </Typography>
      <Button component={Link} to="/" sx={{ mt: 4 }} variant="outlined">
        Ir para o início
      </Button>
    </Box>
  );
}
