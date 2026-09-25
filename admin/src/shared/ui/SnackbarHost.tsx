import { Alert, Button, Snackbar } from '@mui/material';
import { dismissNotification, useNotifications } from './notify';

export function SnackbarHost() {
  const items = useNotifications();
  const current = items[0];
  if (!current) return null;
  const duration = current.action ? 8000 : current.severity === 'error' ? 8000 : 5000;
  return (
    <Snackbar
      key={current.id}
      open
      autoHideDuration={duration}
      onClose={(_, reason) => reason !== 'clickaway' && dismissNotification(current.id)}
      anchorOrigin={{ vertical: 'bottom', horizontal: 'left' }}
    >
      <Alert
        variant="filled"
        severity={current.severity}
        role={current.severity === 'error' ? 'alert' : 'status'}
        onClose={() => dismissNotification(current.id)}
        action={
          current.action ? (
            <Button
              color="inherit"
              size="small"
              onClick={() => {
                current.action?.onClick();
                dismissNotification(current.id);
              }}
            >
              {current.action.label}
            </Button>
          ) : undefined
        }
      >
        {current.message}
      </Alert>
    </Snackbar>
  );
}
