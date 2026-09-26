import { Button, MenuItem, Stack, TextField } from '@mui/material';
import { useState } from 'react';
import { usePriceLists } from '@/shared/api/lookups';
import { errorMessage } from '@/shared/api/errors';
import { Can } from '@/shared/auth';
import { notify } from '@/shared/ui';

/** Atribuir tabela de preço (pricing.manage). */
export function PriceListAssign({ currentId, onSave, pending }: { currentId: number | null; onSave: (id: number | null) => Promise<unknown>; pending: boolean }) {
  const lists = usePriceLists().data ?? [];
  const [value, setValue] = useState<string>(currentId === null ? '' : String(currentId));
  return (
    <Can perm="pricing.manage">
      <Stack direction="row" spacing={2} sx={{ alignItems: 'center', mt: 2 }}>
        <TextField select label="Tabela de preço" value={value} onChange={(e) => setValue(e.target.value)} sx={{ maxWidth: 260 }}>
          <MenuItem value="">Padrão (varejo)</MenuItem>
          {lists.map((l) => (
            <MenuItem key={l.id} value={String(l.id)}>
              {l.name}
            </MenuItem>
          ))}
        </TextField>
        <Button
          disabled={pending || value === (currentId === null ? '' : String(currentId))}
          onClick={() =>
            onSave(value === '' ? null : Number(value))
              .then(() => notify.success('Tabela de preço atribuída'))
              .catch((e: unknown) => notify.error(errorMessage(e)))
          }
        >
          Atribuir
        </Button>
      </Stack>
    </Can>
  );
}
