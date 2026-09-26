import Box from '@mui/material/Box';
import Skeleton from '@mui/material/Skeleton';
import { Suspense } from 'react';
import { Outlet, ScrollRestoration } from 'react-router';
import { MiniCartDrawer } from '@/features/cart';
import { CookieBanner, OfflineBar } from '../components/GlobalNotices';
import { Footer } from '../components/Footer';
import { Header, WhatsAppFab } from '../components/Header';
import { maxContentWidth } from '../theme/tokens';

export function SkipLink() {
  return (
    <Box
      component="a"
      href="#conteudo"
      sx={{ position: 'absolute', left: 8, top: -48, zIndex: 2000, bgcolor: 'background.paper', color: 'primary.main', p: 2, borderRadius: 1, '&:focus': { top: 8 } }}
    >
      Pular para o conteúdo
    </Box>
  );
}

export function PageFallback() {
  return (
    <Box aria-busy="true" sx={{ py: 4 }}>
      <span className="visually-hidden">Carregando</span>
      <Skeleton width="40%" height={48} />
      <Skeleton variant="rectangular" height={240} sx={{ mt: 2, borderRadius: 2 }} />
    </Box>
  );
}

export function StoreLayout() {
  return (
    <Box sx={{ minHeight: '100vh', display: 'flex', flexDirection: 'column' }}>
      <SkipLink />
      <OfflineBar />
      <Header />
      <Box component="main" id="conteudo" tabIndex={-1} sx={{ flex: 1, width: '100%', maxWidth: maxContentWidth, mx: 'auto', px: { xs: 4, sm: 6, md: 8 }, pt: { xs: 4, md: 6 }, outline: 'none' }}>
        <Suspense fallback={<PageFallback />}>
          <Outlet />
        </Suspense>
      </Box>
      <Footer />
      <WhatsAppFab />
      <MiniCartDrawer />
      <CookieBanner />
      <ScrollRestoration />
    </Box>
  );
}
