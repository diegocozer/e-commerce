import Box from '@mui/material/Box';
import List from '@mui/material/List';
import ListItemButton from '@mui/material/ListItemButton';
import Tab from '@mui/material/Tab';
import Tabs from '@mui/material/Tabs';
import Typography from '@mui/material/Typography';
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router';
import { useAuth, useLogout } from '@/features/auth';
import { Seo } from '@/shared/ui/Seo';

const LINKS = [
  { to: '/conta', label: 'Visão geral', end: true },
  { to: '/conta/pedidos', label: 'Pedidos' },
  { to: '/conta/enderecos', label: 'Endereços' },
  { to: '/conta/dados', label: 'Dados' },
  { to: '/conta/senha', label: 'Senha' },
];

export default function AccountLayout() {
  const { customer } = useAuth();
  const logout = useLogout();
  const navigate = useNavigate();
  const { pathname } = useLocation();
  const current = [...LINKS].reverse().find((l) => (l.end ? pathname === l.to : pathname.startsWith(l.to)))?.to ?? '/conta';
  const doLogout = () => logout.mutate(undefined, { onSettled: () => navigate('/', { replace: true }) });
  return (
    <Box sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr', md: '220px 1fr' }, gap: 6 }}>
      <Seo title="Minha conta" robots="noindex,nofollow" />
      <Box component="nav" aria-label="Minha conta" sx={{ display: { xs: 'none', md: 'block' } }}>
        <Typography sx={{ fontWeight: 700 }}>Olá, {customer?.name.split(' ')[0]}</Typography>
        {customer?.company ? <Typography variant="body2" color="text.secondary">{customer.company.trade_name ?? customer.company.legal_name}</Typography> : null}
        <List>
          {LINKS.map((l) => (
            <ListItemButton key={l.to} component={NavLink} to={l.to} end={l.end} selected={current === l.to} aria-current={current === l.to ? 'page' : undefined} sx={{ borderRadius: 2 }}>
              {l.label}
            </ListItemButton>
          ))}
          <ListItemButton onClick={doLogout} sx={{ borderRadius: 2 }}>Sair</ListItemButton>
        </List>
      </Box>
      <Box sx={{ display: { xs: 'block', md: 'none' } }}>
        <Typography variant="h3" component="p" sx={{ mb: 2 }}>Minha conta</Typography>
        <Tabs value={current} variant="scrollable" allowScrollButtonsMobile aria-label="Minha conta">
          {LINKS.map((l) => <Tab key={l.to} label={l.label} value={l.to} component={NavLink} to={l.to} />)}
          <Tab label="Sair" value="sair" onClick={doLogout} />
        </Tabs>
      </Box>
      <Box sx={{ minWidth: 0 }}>
        <Outlet />
      </Box>
    </Box>
  );
}
