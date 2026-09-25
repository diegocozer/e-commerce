import Box from '@mui/material/Box';
import Typography from '@mui/material/Typography';
import type { ReactNode } from 'react';

export function EmptyState({ icon, title, text, action }: { icon: ReactNode; title: string; text?: ReactNode; action?: ReactNode }) {
  return (
    <Box sx={{ textAlign: 'center', py: 12, px: 4, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 3 }}>
      <Box
        aria-hidden
        sx={{ width: 72, height: 72, borderRadius: '50%', bgcolor: 'primary.light', color: 'primary.main', display: 'grid', placeItems: 'center', '& svg': { fontSize: 40 } }}
      >
        {icon}
      </Box>
      <Typography variant="h3" component="h2">
        {title}
      </Typography>
      {text ? <Typography color="text.secondary">{text}</Typography> : null}
      {action}
    </Box>
  );
}
