import { Box, Card, CardContent, Typography } from '@mui/material';
import type { ReactNode } from 'react';
import { Logo } from '@/app/layouts/Logo';

export function AuthCard({ title, children }: { title: string; children: ReactNode }) {
  return (
    <Box component="main" sx={{ minHeight: '100vh', display: 'grid', placeItems: 'center', bgcolor: 'background.default', p: 4 }}>
      <Card sx={{ width: '100%', maxWidth: 400 }}>
        <CardContent sx={{ p: 8 }}>
          <Box sx={{ mb: 6, textAlign: 'center' }}>
            <Logo />
          </Box>
          <Typography variant="h1" sx={{ mb: 4, textAlign: 'center' }}>
            {title}
          </Typography>
          {children}
        </CardContent>
      </Card>
    </Box>
  );
}
