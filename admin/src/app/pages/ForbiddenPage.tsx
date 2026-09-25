import LockIcon from '@mui/icons-material/LockOutlined';
import { Box, Button, Typography } from '@mui/material';
import { Link } from 'react-router-dom';
import { usePageTitle } from '@/shared/hooks/usePageTitle';

export function ForbiddenPage() {
  usePageTitle('Acesso negado');
  return (
    <Box sx={{ textAlign: 'center', py: 16 }}>
      <LockIcon sx={{ fontSize: 56, color: 'text.disabled' }} aria-hidden />
      <Typography variant="h1" sx={{ mt: 2 }}>
        403 — Acesso negado
      </Typography>
      <Typography color="text.secondary" sx={{ mt: 2 }}>
        Você não tem acesso a esta área. Fale com o administrador.
      </Typography>
      <Button component={Link} to="/" sx={{ mt: 4 }} variant="outlined">
        Ir para o início
      </Button>
    </Box>
  );
}
