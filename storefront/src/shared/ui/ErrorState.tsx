import ErrorOutlineOutlined from '@mui/icons-material/ErrorOutlineOutlined';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Typography from '@mui/material/Typography';
import { toApiError } from '../api/errors';

export function ErrorState({
  title = 'Não foi possível carregar.',
  error,
  onRetry,
  compact,
}: {
  title?: string;
  error?: unknown;
  onRetry?: () => void;
  compact?: boolean;
}) {
  const e = error ? toApiError(error) : null;
  return (
    <Box role="alert" sx={{ textAlign: 'center', py: compact ? 4 : 12, px: 4, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 2 }}>
      <ErrorOutlineOutlined color="error" sx={{ fontSize: compact ? 32 : 48 }} aria-hidden />
      <Typography variant={compact ? 'h4' : 'h3'} component="p">
        {title}
      </Typography>
      {e ? <Typography color="text.secondary">{e.message}</Typography> : null}
      {onRetry ? (
        <Button variant="outlined" onClick={onRetry}>
          Tentar novamente
        </Button>
      ) : null}
      {e?.requestId ? (
        <Typography variant="caption" color="text.secondary">
          Código para suporte: {e.requestId}
        </Typography>
      ) : null}
    </Box>
  );
}
