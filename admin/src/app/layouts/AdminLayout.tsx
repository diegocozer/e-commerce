import { Box, Drawer, LinearProgress } from '@mui/material';
import { Suspense, useState } from 'react';
import { Outlet } from 'react-router-dom';
import { useOrderStatusCounts } from '@/features/orders/api';
import { useCan } from '@/shared/auth';
import { SIDEBAR_COLLAPSED_WIDTH, SIDEBAR_WIDTH } from '../tokens';
import { Sidebar } from './Sidebar';
import { TopBar } from './TopBar';

const COLLAPSE_KEY = 'cv_admin_sidebar_collapsed';

function readCollapsed(): boolean {
  try {
    return localStorage.getItem(COLLAPSE_KEY) === '1';
  } catch {
    return false;
  }
}

export function AdminLayout() {
  const [collapsed, setCollapsed] = useState(readCollapsed);
  const [mobileOpen, setMobileOpen] = useState(false);
  const canOrders = useCan('orders.view');
  const counts = useOrderStatusCounts({}, { enabled: canOrders, refetchInterval: 60_000 });
  const toPick = counts.data?.paid ?? 0;
  const width = collapsed ? SIDEBAR_COLLAPSED_WIDTH : SIDEBAR_WIDTH;

  const toggle = () => {
    setCollapsed((c) => {
      try {
        localStorage.setItem(COLLAPSE_KEY, c ? '0' : '1');
      } catch {
        /* preferências são opcionais */
      }
      return !c;
    });
  };

  return (
    <Box sx={{ display: 'flex', minHeight: '100vh', bgcolor: 'background.default' }}>
      <Box
        component="a"
        href="#conteudo"
        sx={{ position: 'absolute', left: -9999, '&:focus': { left: 8, top: 8, zIndex: 2000, bgcolor: 'background.paper', p: 2 } }}
      >
        Pular para o conteúdo
      </Box>
      <Drawer
        variant="permanent"
        sx={{ display: { xs: 'none', lg: 'block' }, width, flexShrink: 0, '& .MuiDrawer-paper': { width, boxSizing: 'border-box', overflowX: 'hidden' } }}
      >
        <Sidebar collapsed={collapsed} onToggleCollapsed={toggle} toPick={toPick} />
      </Drawer>
      <Drawer
        variant="temporary"
        open={mobileOpen}
        onClose={() => setMobileOpen(false)}
        sx={{ display: { lg: 'none' }, '& .MuiDrawer-paper': { width: SIDEBAR_WIDTH } }}
        ModalProps={{ keepMounted: true }}
      >
        <Sidebar collapsed={false} onNavigate={() => setMobileOpen(false)} toPick={toPick} />
      </Drawer>
      <Box sx={{ flex: 1, minWidth: 0, display: 'flex', flexDirection: 'column' }}>
        <TopBar onMenu={() => setMobileOpen(true)} />
        <Box component="main" id="conteudo" sx={{ p: { xs: 4, md: 6, lg: 8 }, flex: 1, minWidth: 0 }}>
          <Suspense fallback={<LinearProgress aria-label="Carregando página" />}>
            <Outlet />
          </Suspense>
        </Box>
      </Box>
    </Box>
  );
}
