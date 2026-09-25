import { Alert, Link, List, ListItem } from '@mui/material';
import type { FieldErrors, FieldValues } from 'react-hook-form';

interface Flat {
  path: string;
  message: string;
}

export function flattenErrors(errors: FieldErrors, prefix = ''): Flat[] {
  const out: Flat[] = [];
  for (const [key, value] of Object.entries(errors)) {
    if (!value || key === 'ref') continue;
    const path = prefix ? `${prefix}.${key}` : key;
    const v = value as { message?: unknown; type?: unknown };
    if (typeof v.message === 'string' && v.message) out.push({ path, message: v.message });
    else if (typeof value === 'object') out.push(...flattenErrors(value as FieldErrors, path));
  }
  return out;
}

/** Resumo no topo: "Corrija N campos" com links que focam cada campo (UX §6.5). */
export function FormErrorSummary<T extends FieldValues>({ errors, extra = [], labels = {}, onFocusField }: { errors: FieldErrors<T>; extra?: string[]; labels?: Record<string, string>; onFocusField?: (path: string) => void }) {
  const flat = flattenErrors(errors as FieldErrors);
  const total = flat.length + extra.length;
  if (total === 0) return null;
  return (
    <Alert severity="error" role="alert" sx={{ mb: 4 }}>
      <strong>{total === 1 ? 'Corrija 1 campo' : `Corrija ${total} campos`}</strong>
      <List dense disablePadding>
        {flat.map((e) => (
          <ListItem key={e.path} disablePadding>
            <Link
              component="button"
              type="button"
              onClick={() => {
                if (onFocusField) onFocusField(e.path);
                else (document.querySelector(`[name="${e.path}"]`) as HTMLElement | null)?.focus();
              }}
            >
              {labels[e.path] ? `${labels[e.path]}: ` : ''}
              {e.message}
            </Link>
          </ListItem>
        ))}
        {extra.map((m) => (
          <ListItem key={m} disablePadding>
            {m}
          </ListItem>
        ))}
      </List>
    </Alert>
  );
}
