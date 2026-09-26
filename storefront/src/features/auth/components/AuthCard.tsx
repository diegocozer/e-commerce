import Card from '@mui/material/Card';
import type { ReactNode } from 'react';

export function AuthCard({ children, width = 440 }: { children: ReactNode; width?: number }) {
  return <Card sx={{ p: { xs: 4, sm: 8 }, maxWidth: width, mx: 'auto', my: { xs: 2, md: 8 } }}>{children}</Card>;
}
