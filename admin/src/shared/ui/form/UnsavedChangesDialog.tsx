import type { Blocker } from 'react-router-dom';
import { ConfirmDialog } from '../ConfirmDialog';

export function UnsavedChangesDialog({ blocker }: { blocker: Blocker }) {
  return (
    <ConfirmDialog
      open={blocker.state === 'blocked'}
      title="Descartar alterações não salvas?"
      description="Você tem alterações que ainda não foram salvas."
      confirmLabel="Descartar"
      cancelLabel="Continuar editando"
      destructive
      onConfirm={() => blocker.proceed?.()}
      onClose={() => blocker.reset?.()}
    />
  );
}
