import Box, { type BoxProps } from '@mui/material/Box';
import { useMemo } from 'react';
import { sanitizeHtml } from '../lib/safeHtml';

/** Renderiza HTML de descrição sanitizado com DOMPurify (ADR-024). */
export function SafeHtml({ html, ...rest }: { html: string } & Omit<BoxProps, 'dangerouslySetInnerHTML' | 'children'>) {
  const clean = useMemo(() => sanitizeHtml(html), [html]);
  return (
    <Box
      {...rest}
      sx={{ '& p': { my: 2 }, '& ul, & ol': { pl: 6 }, '& table': { borderCollapse: 'collapse' }, '& td, & th': { border: '1px solid', borderColor: 'divider', p: 2 }, ...rest.sx }}
      dangerouslySetInnerHTML={{ __html: clean }}
    />
  );
}
