import Box from '@mui/material/Box';
import { Link as RouterLink } from 'react-router';

export function Logo({ color = '#fff' }: { color?: string }) {
  return (
    <Box component={RouterLink} to="/" aria-label="Comunika Suprimentos — página inicial" sx={{ textDecoration: 'none', color, lineHeight: 1, display: 'inline-flex', flexDirection: 'column', '&:focus-visible': { outline: '2px solid #FFB74D', outlineOffset: 2 } }}>
      <Box component="span" sx={{ fontFamily: 'Manrope, sans-serif', fontWeight: 800, fontSize: 24, letterSpacing: '-0.02em' }}>
        comuni<Box component="span" sx={{ color: '#F28C4B' }}>k</Box>a
      </Box>
      <Box component="span" sx={{ fontSize: 10, fontWeight: 600, letterSpacing: '0.16em' }}>SUPRIMENTOS</Box>
    </Box>
  );
}
