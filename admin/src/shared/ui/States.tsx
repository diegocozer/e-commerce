import InboxIcon from '@mui/icons-material/InboxOutlined';
import { Alert, Box, Button, Skeleton, Stack, Typography } from '@mui/material';
import type { ReactNode } from 'react';
import { errorMessage, isApiError } from '@/shared/api/errors';

export function EmptyState({ title, description, action }: { title: string; description?: string; action?: ReactNode }) {
  return (
    <Box sx={{ py: 10, px: 4, textAlign: 'center' }}>
      <InboxIcon sx={{ fontSize: 48, color: 'text.disabled' }} aria-hidden />
      <Typography variant="h3" sx={{ mt: 2 }}>
        {title}
      </Typography>
      {description && (
        <Typography variant="body2" color="text.secondary" sx={{ mt: 1 }}>
          {description}
        </Typography>
      )}
      {action && <Box sx={{ mt: 4 }}>{action}</Box>}
    </Box>
  );
}

export function ErrorState({ error, onRetry, title = 'Não foi possível carregar.' }: { error: unknown; onRetry?: () => void; title?: string }) {
  const requestId = isApiError(error) ? error.requestId : null;
  return (
    <Alert
      severity="error"
      role="alert"
      action={
        onRetry ? (
          <Button color="inherit" size="small" onClick={onRetry}>
            Tentar novamente
          </Button>
        ) : undefined
      }
    >
      <strong>{title}</strong> {errorMessage(error)}
      {requestId && (
        <Typography variant="caption" component="div">
          Código para suporte: {requestId}
        </Typography>
      )}
    </Alert>
  );
}

export function LoadingBlock({ lines = 4, label = 'Carregando' }: { lines?: number; label?: string }) {
  return (
    <Stack spacing={2} aria-busy="true" aria-label={label}>
      {Array.from({ length: lines }, (_, i) => (
        <Skeleton key={i} variant="rounded" height={i === 0 ? 40 : 24} />
      ))}
    </Stack>
  );
}
