import { Box, Typography } from '@mui/material';

/** Wordmark placeholder "comunika · Painel" (UX §2.1). */
export function Logo({ compact = false }: { compact?: boolean }) {
  if (compact) {
    return (
      <Box aria-label="Comunika Painel" role="img" sx={{ width: 36, height: 36, borderRadius: 2, bgcolor: 'primary.main', display: 'grid', placeItems: 'center' }}>
        <Typography sx={{ fontFamily: 'Manrope, sans-serif', fontWeight: 800, color: '#C2410C', fontSize: 22, lineHeight: 1 }}>k</Typography>
      </Box>
    );
  }
  return (
    <Box sx={{ lineHeight: 1 }}>
      <Typography component="span" sx={{ fontFamily: 'Manrope, sans-serif', fontWeight: 800, fontSize: 22, color: 'primary.main' }}>
        comuni<Box component="span" sx={{ color: 'secondary.main' }}>k</Box>a
      </Typography>
      <Typography component="div" sx={{ fontSize: 10, fontWeight: 600, letterSpacing: '0.16em', color: 'text.secondary' }}>
        SUPRIMENTOS · PAINEL
      </Typography>
    </Box>
  );
}
