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
  const input = { uuid: params.get('uuid') ?? '', hash: params.get('hash') ?? '', expires: params.get('expires') ?? '', signature: params.get('signature') ?? '' };
  // Link incompleto: nem chama a API (evita 422 inútil) — mostra "link inválido".
  const complete = Object.values(input).every(Boolean);
  const [state, setState] = useState<'loading' | 'ok' | 'error'>(complete ? 'loading' : 'error');
  const sent = useRef(false);
  useEffect(() => {
    if (sent.current || !complete) return;
    sent.current = true;
    verifyEmail({ uuid: params.get('uuid') ?? '', hash: params.get('hash') ?? '', expires: params.get('expires') ?? '', signature: params.get('signature') ?? '' }).then(
      () => setState('ok'),
      () => setState('error'),
    );
  }, [params, complete]);
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
