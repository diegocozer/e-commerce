import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Link from '@mui/material/Link';
import TextField from '@mui/material/TextField';
import { useEffect, useState, type FormEvent } from 'react';
import { Link as RouterLink } from 'react-router';
import { describeError } from '@/shared/api/errors';
import { PageHeading } from '@/shared/ui/PageHeading';
import { Seo } from '@/shared/ui/Seo';
import { forgotPassword } from '../api';
import { AuthCard } from '../components/AuthCard';

export default function ForgotPasswordPage() {
  const [email, setEmail] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [sentTo, setSentTo] = useState<string | null>(null);
  const [wait, setWait] = useState(0);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    if (wait <= 0) return;
    const id = setTimeout(() => setWait((w) => w - 1), 1000);
    return () => clearTimeout(id);
  }, [wait]);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (!/^\S+@\S+\.\S+$/.test(email.trim())) {
      setError('Informe um e-mail válido');
      return;
    }
    setError(null);
    setLoading(true);
    try {
      await forgotPassword(email.trim().toLowerCase());
      setSentTo(email.trim());
      setWait(60);
    } catch (err) {
      setError(describeError(err));
    } finally {
      setLoading(false);
    }
  };

  return (
    <AuthCard>
      <Seo title="Recuperar senha" robots="noindex,nofollow" />
      <PageHeading sx={{ fontSize: 28 }}>Recuperar senha</PageHeading>
      {sentTo ? (
        <Alert severity="success" sx={{ mb: 4 }} role="status">
          Se houver uma conta com {sentTo}, enviamos um link para redefinir a senha. Verifique também o spam.
        </Alert>
      ) : null}
      <Box component="form" onSubmit={submit} noValidate sx={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
        <TextField label="E-mail" type="email" required value={email} onChange={(e) => setEmail(e.target.value)} error={Boolean(error)} helperText={error ?? undefined} slotProps={{ htmlInput: { autoComplete: 'email' } }} />
        <Button type="submit" loading={loading} disabled={wait > 0}>
          {sentTo ? (wait > 0 ? `Reenviar em ${wait} s` : 'Reenviar') : 'Enviar link'}
        </Button>
        <Link component={RouterLink} to="/entrar">Voltar para entrar</Link>
      </Box>
    </AuthCard>
  );
}
