import { Box, Typography } from '@mui/material';
import type { ReactNode } from 'react';

/** Lista de pares rótulo/valor para cards de detalhe. */
export function KeyValue({ items }: { items: { label: string; value: ReactNode }[] }) {
  return (
    <Box component="dl" sx={{ display: 'grid', gridTemplateColumns: 'minmax(120px, max-content) 1fr', columnGap: 4, rowGap: 1.5, m: 0 }}>
      {items.map((i) => (
        <Box key={i.label} sx={{ display: 'contents' }}>
          <Typography component="dt" variant="body2" color="text.secondary">
            {i.label}
          </Typography>
          <Typography component="dd" variant="body2" sx={{ m: 0, overflowWrap: 'anywhere' }}>
            {i.value}
          </Typography>
        </Box>
      ))}
    </Box>
  );
}
