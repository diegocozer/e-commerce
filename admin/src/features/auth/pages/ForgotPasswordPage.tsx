import { Alert, Button, Link, Stack, TextField } from '@mui/material';
import { useMutation } from '@tanstack/react-query';
import { useState } from 'react';
import { Link as RouterLink } from 'react-router-dom';
import { errorMessage } from '@/shared/api/errors';
import { usePageTitle } from '@/shared/hooks/usePageTitle';
import { forgotPassword } from '../api';
import { AuthCard } from './AuthCard';

export default function ForgotPasswordPage() {
  usePageTitle('Recuperar senha');
  const [email, setEmail] = useState('');
  const m = useMutation({ mutationFn: forgotPassword });
  return (
    <AuthCard title="Recuperar senha">
      <Stack
        component="form"
        spacing={4}
        onSubmit={(e) => {
          e.preventDefault();
          if (email) m.mutate(email);
        }}
      >
        {m.isSuccess && <Alert severity="success">{m.data.data.message}</Alert>}
        {m.isError && <Alert severity="error">{errorMessage(m.error)}</Alert>}
        <TextField label="E-mail" type="email" required value={email} onChange={(e) => setEmail(e.target.value)} autoComplete="username" />
        <Button type="submit" variant="contained" disabled={m.isPending}>
          Enviar link de redefinição
        </Button>
        <Link component={RouterLink} to="/entrar" sx={{ textAlign: 'center' }}>
          Voltar para o login
        </Link>
      </Stack>
    </AuthCard>
  );
}
