import { Box, Table, TableBody, TableCell, TableHead, TableRow, Typography } from '@mui/material';

const show = (v: unknown): string => (v === undefined ? '—' : v === null ? 'null' : typeof v === 'object' ? JSON.stringify(v) : String(v));

export interface DiffRow {
  field: string;
  before: unknown;
  after: unknown;
  changed: boolean;
}

export function diffRows(oldV: Record<string, unknown> | null, newV: Record<string, unknown> | null): DiffRow[] {
  const keys = Array.from(new Set([...Object.keys(oldV ?? {}), ...Object.keys(newV ?? {})])).sort();
  return keys.map((field) => {
    const before = oldV?.[field];
    const after = newV?.[field];
    return { field, before, after, changed: JSON.stringify(before) !== JSON.stringify(after) };
  });
}

/** Diff campo a campo com prefixos "−"/"+" (cor não é o único sinal — UX §5.14). */
export function DiffViewer({ oldValues, newValues }: { oldValues: Record<string, unknown> | null; newValues: Record<string, unknown> | null }) {
  const rows = diffRows(oldValues, newValues);
  if (!rows.length) return <Typography variant="body2">Sem alterações de campos.</Typography>;
  return (
    <Table size="small" aria-label="Diferenças">
      <TableHead>
        <TableRow>
          <TableCell>Campo</TableCell>
          <TableCell>Antes</TableCell>
          <TableCell>Depois</TableCell>
        </TableRow>
      </TableHead>
      <TableBody>
        {rows.map((r) => (
          <TableRow key={r.field}>
            <TableCell sx={{ fontWeight: 600 }}>{r.field}</TableCell>
            <TableCell sx={{ bgcolor: r.changed ? 'error.light' : undefined, color: r.changed ? 'error.dark' : undefined }}>
              <Box component="code" sx={{ whiteSpace: 'pre-wrap', wordBreak: 'break-word' }}>{r.changed ? `− ${show(r.before)}` : show(r.before)}</Box>
            </TableCell>
            <TableCell sx={{ bgcolor: r.changed ? 'success.light' : undefined, color: r.changed ? 'success.dark' : undefined }}>
              <Box component="code" sx={{ whiteSpace: 'pre-wrap', wordBreak: 'break-word' }}>{r.changed ? `+ ${show(r.after)}` : show(r.after)}</Box>
            </TableCell>
          </TableRow>
        ))}
      </TableBody>
    </Table>
  );
}
