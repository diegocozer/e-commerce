import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Snackbar from '@mui/material/Snackbar';
import { createContext, useCallback, useContext, useMemo, useRef, useState, type ReactNode } from 'react';

export type SnackSeverity = 'success' | 'error' | 'info' | 'warning';
export interface SnackOptions {
  severity?: SnackSeverity;
  actionLabel?: string;
  onAction?: () => void;
  /** ms; null = persiste até fechar. */
  duration?: number | null;
}
interface Snack extends SnackOptions {
  id: number;
  message: string;
}

type Notify = (message: string, options?: SnackOptions) => void;
const SnackContext = createContext<Notify>(() => undefined);

/** Fila de snackbars (UX §6.3): um por vez; 5 s, 8 s com ação; erros com ação persistem. */
export function SnackbarProvider({ children }: { children: ReactNode }) {
  const [queue, setQueue] = useState<Snack[]>([]);
  const idRef = useRef(0);
  const current = queue[0];

  const notify = useCallback<Notify>((message, options) => {
    idRef.current += 1;
    const id = idRef.current;
    setQueue((q) => [...q, { id, message, ...options }]);
  }, []);

  const close = useCallback(() => setQueue((q) => q.slice(1)), []);

  const duration = current
    ? current.duration !== undefined
      ? current.duration
      : current.severity === 'error' && current.onAction
        ? null
        : current.onAction
          ? 8000
          : 5000
    : 5000;

  return (
    <SnackContext.Provider value={notify}>
      {children}
      <Snackbar
        key={current?.id}
        open={Boolean(current)}
        autoHideDuration={duration}
        onClose={(_, reason) => {
          if (reason !== 'clickaway') close();
        }}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }}
        sx={{ bottom: { xs: 88, md: 24 } }}
      >
        {current ? (
          <Alert
            severity={current.severity ?? 'success'}
            variant="filled"
            onClose={close}
            role={current.severity === 'error' ? 'alert' : 'status'}
            action={
              current.actionLabel ? (
                <Button
                  color="inherit"
                  size="small"
                  onClick={() => {
                    current.onAction?.();
                    close();
                  }}
                >
                  {current.actionLabel}
                </Button>
              ) : undefined
            }
          >
            {current.message}
          </Alert>
        ) : undefined}
      </Snackbar>
    </SnackContext.Provider>
  );
}

export function useSnackbar(): Notify {
  return useContext(SnackContext);
}

export function useStableNotify(): Notify {
  const notify = useSnackbar();
  return useMemo(() => notify, [notify]);
}
