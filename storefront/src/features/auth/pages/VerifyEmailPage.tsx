import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import CircularProgress from '@mui/material/CircularProgress';
import { useEffect, useRef, useState } from 'react';
import { Link as RouterLink, useSearchParams } from 'react-router';
import { PageHeading } from '@/shared/ui/PageHeading';
import { Seo } from '@/shared/ui/Seo';
import { verifyEmail } from '../api';
import { AuthCard } from '../components/AuthCard';

export default function VerifyEmailPage() {
  const [params] = useSearchParams();
  const [state, setState] = useState<'loading' | 'ok' | 'error'>('loading');
  const sent = useRef(false);
  useEffect(() => {
    if (sent.current) return;
    sent.current = true;
    const input = { uuid: params.get('uuid') ?? '', hash: params.get('hash') ?? '', expires: params.get('expires') ?? '', signature: params.get('signature') ?? '' };
    verifyEmail(input).then(() => setState('ok'), () => setState('error'));
  }, [params]);
  return (
    <AuthCard>
      <Seo title="Confirmar e-mail" robots="noindex,nofollow" />
      <PageHeading sx={{ fontSize: 28 }}>Confirmar e-mail</PageHeading>
      {state === 'loading' ? <CircularProgress aria-label="Confirmando" /> : null}
      {state === 'ok' ? <Alert severity="success">E-mail confirmado. Obrigado!</Alert> : null}
      {state === 'error' ? <Alert severity="error">Este link expirou ou é inválido.</Alert> : null}
      <Button component={RouterLink} to="/" sx={{ mt: 4 }}>Ir para a loja</Button>
    </AuthCard>
  );
}
