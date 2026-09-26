import ArrowBackIcon from '@mui/icons-material/ArrowBackOutlined';
import { Box, Link, Stack, Typography } from '@mui/material';
import { useEffect, useRef, type ReactNode } from 'react';
import { Link as RouterLink } from 'react-router-dom';
import { usePageTitle } from '@/shared/hooks/usePageTitle';

interface Props {
  title: string;
  count?: number | null;
  subtitle?: ReactNode;
  actions?: ReactNode;
  back?: { to: string; label: string };
  chips?: ReactNode;
}

/** Cabeçalho de página: h1 (+ contagem), ação primária à direita (UX §5.1). Foca o h1 na troca de rota. */
export function PageHeader({ title, count, subtitle, actions, back, chips }: Props) {
  usePageTitle(title);
  const ref = useRef<HTMLHeadingElement>(null);
  // Foco no h1 a cada troca de página/título (UX §6.10).
  useEffect(() => {
    if (title) ref.current?.focus({ preventScroll: true });
  }, [title]);
  return (
    <Box sx={{ mb: 4 }}>
      {back && (
        <Link component={RouterLink} to={back.to} sx={{ display: 'inline-flex', alignItems: 'center', gap: 1, mb: 1 }}>
          <ArrowBackIcon fontSize="inherit" aria-hidden /> {back.label}
        </Link>
      )}
      <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2} sx={{ alignItems: { sm: 'center' }, justifyContent: 'space-between' }}>
        <Stack direction="row" spacing={2} sx={{ alignItems: 'center', flexWrap: 'wrap', rowGap: 1 }}>
          <Typography variant="h1" ref={ref} tabIndex={-1} sx={{ outline: 'none' }}>
            {title}
            {typeof count === 'number' && (
              <Typography component="span" variant="h2" color="text.secondary" className="num" sx={{ ml: 2, fontWeight: 600 }}>
                ({new Intl.NumberFormat('pt-BR').format(count)})
              </Typography>
            )}
          </Typography>
          {chips}
        </Stack>
        {actions && (
          <Stack direction="row" spacing={2} sx={{ flexWrap: 'wrap', rowGap: 2 }}>
            {actions}
          </Stack>
        )}
      </Stack>
      {subtitle && (
        <Typography variant="body2" color="text.secondary" sx={{ mt: 1 }}>
          {subtitle}
        </Typography>
      )}
    </Box>
  );
}
