import LockOutlined from '@mui/icons-material/LockOutlined';
import WhatsApp from '@mui/icons-material/WhatsApp';
import AppBar from '@mui/material/AppBar';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Typography from '@mui/material/Typography';
import { Suspense } from 'react';
import { Outlet, ScrollRestoration } from 'react-router';
import { useSettings } from '@/features/catalog/hooks/queries';
import { Footer } from '../components/Footer';
import { OfflineBar } from '../components/GlobalNotices';
import { Logo } from '../components/Logo';
import { maxContentWidth } from '../theme/tokens';
import { PageFallback, SkipLink } from './StoreLayout';

/** Header reduzido, sem menu/busca/footer completo (UX §3.4). */
export function CheckoutLayout() {
  const wa = useSettings().data?.store.whatsapp;
  return (
    <Box sx={{ minHeight: '100vh', display: 'flex', flexDirection: 'column' }}>
      <SkipLink />
      <OfflineBar />
      <AppBar component="header" position="static" elevation={0} sx={{ bgcolor: 'header' }}>
        <Box sx={{ maxWidth: maxContentWidth, mx: 'auto', width: '100%', px: { xs: 4, md: 8 }, py: 3, display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 2 }}>
          <Logo />
          <Typography sx={{ display: 'flex', alignItems: 'center', gap: 1, fontWeight: 600 }}>
            <LockOutlined fontSize="small" aria-hidden /> Compra segura
          </Typography>
          {wa ? (
            <Button href={`https://wa.me/55${wa}`} target="_blank" rel="noopener" variant="text" sx={{ color: '#fff' }} startIcon={<WhatsApp />}>
              Ajuda
            </Button>
          ) : <span />}
        </Box>
      </AppBar>
      <Box component="main" id="conteudo" tabIndex={-1} sx={{ flex: 1, width: '100%', maxWidth: maxContentWidth, mx: 'auto', px: { xs: 4, sm: 6, md: 8 }, pt: { xs: 4, md: 6 }, outline: 'none' }}>
        <Suspense fallback={<PageFallback />}>
          <Outlet />
        </Suspense>
      </Box>
      <Footer minimal />
      <ScrollRestoration />
    </Box>
  );
}
