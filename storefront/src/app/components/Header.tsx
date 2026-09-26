import ExpandMore from '@mui/icons-material/ExpandMore';
import LocationOnOutlined from '@mui/icons-material/LocationOnOutlined';
import Menu from '@mui/icons-material/Menu';
import PersonOutlineOutlined from '@mui/icons-material/PersonOutlineOutlined';
import ShoppingCartOutlined from '@mui/icons-material/ShoppingCartOutlined';
import WhatsApp from '@mui/icons-material/WhatsApp';
import Accordion from '@mui/material/Accordion';
import AccordionDetails from '@mui/material/AccordionDetails';
import AccordionSummary from '@mui/material/AccordionSummary';
import AppBar from '@mui/material/AppBar';
import Badge from '@mui/material/Badge';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import Divider from '@mui/material/Divider';
import Drawer from '@mui/material/Drawer';
import IconButton from '@mui/material/IconButton';
import List from '@mui/material/List';
import ListItemButton from '@mui/material/ListItemButton';
import MuiMenu from '@mui/material/Menu';
import MenuItem from '@mui/material/MenuItem';
import Popover from '@mui/material/Popover';
import Typography from '@mui/material/Typography';
import useMediaQuery from '@mui/material/useMediaQuery';
import { useTheme } from '@mui/material/styles';
import { useRef, useState } from 'react';
import { Link as RouterLink, useNavigate } from 'react-router';
import { useAuth, useLogout } from '@/features/auth';
import { useCart, useMiniCart } from '@/features/cart';
import { useCategoryTree, useSettings } from '@/features/catalog/hooks/queries';
import type { CategoryNode } from '@/shared/api/types';
import { formatBRL } from '@/shared/formatters/money';
import { formatCEP } from '@/shared/formatters/postalCode';
import { formatPhone } from '@/shared/formatters/phone';
import { usePreferredPostalCode } from '@/shared/hooks/usePostalCode';
import { maxContentWidth } from '../theme/tokens';
import { CepDialog } from './CepDialog';
import { Logo } from './Logo';
import { SearchAutocomplete } from './SearchAutocomplete';

const focusOnBlue = { '&:focus-visible': { outline: '2px solid #FFB74D', outlineOffset: 2 } };

function CartButton() {
  const cart = useCart();
  const mini = useMiniCart();
  const navigate = useNavigate();
  const theme = useTheme();
  const desktop = useMediaQuery(theme.breakpoints.up('md'));
  const count = cart.data?.items_count ?? 0;
  const subtotal = cart.data?.totals.subtotal_cents ?? 0;
  return (
    <Button
      color="inherit"
      variant="text"
      onClick={() => (desktop ? mini.show() : navigate('/carrinho'))}
      aria-label={`Carrinho, ${count} ${count === 1 ? 'item' : 'itens'}, subtotal ${formatBRL(subtotal)}`}
      sx={{ color: '#fff', minWidth: 44, gap: 2, ...focusOnBlue }}
    >
      <Badge badgeContent={count} color="secondary" max={99}>
        <ShoppingCartOutlined />
      </Badge>
      <Box component="span" className="num" sx={{ display: { xs: 'none', md: 'inline' }, fontSize: 14 }}>
        {formatBRL(subtotal)}
      </Box>
    </Button>
  );
}

function AccountButton() {
  const { customer } = useAuth();
  const logout = useLogout();
  const navigate = useNavigate();
  const [anchor, setAnchor] = useState<HTMLElement | null>(null);
  if (!customer) {
    return (
      <Button component={RouterLink} to="/entrar" color="inherit" variant="text" startIcon={<PersonOutlineOutlined />} sx={{ color: '#fff', ...focusOnBlue }} aria-label="Entrar ou cadastrar">
        <Box component="span" sx={{ display: { xs: 'none', md: 'inline' } }}>Entrar / Cadastrar</Box>
      </Button>
    );
  }
  const first = customer.name.split(' ')[0];
  return (
    <>
      <Button color="inherit" variant="text" onClick={(e) => setAnchor(e.currentTarget)} startIcon={<PersonOutlineOutlined />} endIcon={<ExpandMore />} sx={{ color: '#fff', ...focusOnBlue }} aria-haspopup="menu" aria-label={`Minha conta — Olá, ${first}`}>
        <Box component="span" sx={{ display: { xs: 'none', md: 'flex' }, flexDirection: 'column', alignItems: 'flex-start', lineHeight: 1.1 }}>
          <span>Olá, {first}</span>
          {customer.company ? <Box component="span" sx={{ fontSize: 11, opacity: 0.85 }}>{customer.company.trade_name ?? customer.company.legal_name}</Box> : null}
        </Box>
      </Button>
      <MuiMenu anchorEl={anchor} open={Boolean(anchor)} onClose={() => setAnchor(null)}>
        {[
          ['Minha conta', '/conta'],
          ['Meus pedidos', '/conta/pedidos'],
          ['Endereços', '/conta/enderecos'],
        ].map(([label, to]) => (
          <MenuItem key={to} component={RouterLink} to={to} onClick={() => setAnchor(null)}>
            {label}
          </MenuItem>
        ))}
        <MenuItem onClick={() => { setAnchor(null); logout.mutate(undefined, { onSettled: () => navigate('/') }); }}>Sair</MenuItem>
      </MuiMenu>
    </>
  );
}

function CepChip() {
  const [cep] = usePreferredPostalCode();
  const [open, setOpen] = useState(false);
  return (
    <>
      <Chip
        icon={<LocationOnOutlined sx={{ color: 'primary.main !important' }} />}
        label={cep ? `Entregar em ${formatCEP(cep)}` : 'Informe seu CEP'}
        onClick={() => setOpen(true)}
        sx={{ bgcolor: 'primary.light', color: 'primary.main', height: 32, ...focusOnBlue }}
        aria-haspopup="dialog"
      />
      {open ? <CepDialog open onClose={() => setOpen(false)} /> : null}
    </>
  );
}

function MegaMenu({ tree }: { tree: CategoryNode[] }) {
  const [anchor, setAnchor] = useState<HTMLElement | null>(null);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const openDelayed = (el: HTMLElement) => {
    timer.current = setTimeout(() => setAnchor(el), 150);
  };
  return (
    <>
      <Button
        variant="text"
        color="inherit"
        startIcon={<Menu />}
        endIcon={<ExpandMore />}
        onClick={(e) => setAnchor(e.currentTarget)}
        onMouseEnter={(e) => openDelayed(e.currentTarget)}
        onMouseLeave={() => timer.current && clearTimeout(timer.current)}
        aria-haspopup="true"
        aria-expanded={Boolean(anchor)}
        sx={{ color: 'text.primary', whiteSpace: 'nowrap' }}
      >
        Categorias
      </Button>
      <Popover open={Boolean(anchor)} anchorEl={anchor} onClose={() => setAnchor(null)} anchorOrigin={{ vertical: 'bottom', horizontal: 'left' }} slotProps={{ paper: { sx: { p: 4, maxWidth: 960 } } }}>
        <Box component="nav" aria-label="Todas as categorias" sx={{ display: 'grid', gridTemplateColumns: 'repeat(4, minmax(160px, 1fr))', gap: 4 }}>
          {tree.map((c) => (
            <Box key={c.id}>
              <Button component={RouterLink} to={c.url_path} variant="text" onClick={() => setAnchor(null)} sx={{ fontWeight: 700, px: 0 }}>{c.name}</Button>
              {c.children.map((s) => (
                <Box key={s.id}>
                  <Button component={RouterLink} to={s.url_path} variant="text" size="small" onClick={() => setAnchor(null)} sx={{ color: 'text.secondary', px: 0, minHeight: 32 }}>{s.name}</Button>
                </Box>
              ))}
            </Box>
          ))}
        </Box>
      </Popover>
    </>
  );
}

function MobileDrawer({ open, onClose, tree }: { open: boolean; onClose: () => void; tree: CategoryNode[] }) {
  const settings = useSettings();
  const wa = settings.data?.store.whatsapp;
  return (
    <Drawer open={open} onClose={onClose} slotProps={{ paper: { sx: { width: 300 } } }}>
      <Box component="nav" aria-label="Menu" sx={{ p: 3 }}>
        <Typography variant="overline" component="h2">Categorias</Typography>
        {tree.map((c) =>
          c.children.length ? (
            <Accordion key={c.id} disableGutters elevation={0}>
              <AccordionSummary expandIcon={<ExpandMore />}>{c.name}</AccordionSummary>
              <AccordionDetails sx={{ py: 0 }}>
                <List dense>
                  <ListItemButton component={RouterLink} to={c.url_path} onClick={onClose}>Ver tudo em {c.name}</ListItemButton>
                  {c.children.map((s) => <ListItemButton key={s.id} component={RouterLink} to={s.url_path} onClick={onClose}>{s.name}</ListItemButton>)}
                </List>
              </AccordionDetails>
            </Accordion>
          ) : (
            <ListItemButton key={c.id} component={RouterLink} to={c.url_path} onClick={onClose}>{c.name}</ListItemButton>
          ),
        )}
        <Divider sx={{ my: 2 }} />
        <List>
          <ListItemButton component={RouterLink} to="/conta" onClick={onClose}>Minha conta</ListItemButton>
          <ListItemButton component={RouterLink} to="/conta/pedidos" onClick={onClose}>Meus pedidos</ListItemButton>
          {wa ? <ListItemButton component="a" href={`https://wa.me/55${wa}`} target="_blank" rel="noopener">Atendimento/WhatsApp</ListItemButton> : null}
        </List>
      </Box>
    </Drawer>
  );
}

/** Header da loja (UX §3.2). */
export function Header() {
  const tree = useCategoryTree();
  const settings = useSettings();
  const [drawer, setDrawer] = useState(false);
  const banner = settings.data?.free_shipping_banner;
  const pickup = settings.data?.pickup_points[0];
  const phone = settings.data?.store.phone;
  const cats = tree.data ?? [];
  return (
    <AppBar component="header" position="sticky" elevation={0} sx={{ bgcolor: 'header', color: '#fff' }}>
      <Box sx={{ display: { xs: 'none', md: 'block' }, bgcolor: 'primary.dark', fontSize: 13, py: 1 }}>
        <Box sx={{ maxWidth: maxContentWidth, mx: 'auto', px: 8, display: 'flex', gap: 2, justifyContent: 'center' }}>
          {[pickup ? `Retirada grátis em ${pickup.city}` : null, banner?.enabled ? banner.text : null, phone ? formatPhone(phone) : null].filter(Boolean).join(' · ')}
        </Box>
      </Box>
      <Box sx={{ maxWidth: maxContentWidth, mx: 'auto', width: '100%', px: { xs: 4, sm: 6, md: 8 }, py: 2, display: 'flex', alignItems: 'center', gap: { xs: 2, md: 6 }, flexWrap: { xs: 'wrap', md: 'nowrap' } }}>
        <IconButton aria-label="Abrir menu" onClick={() => setDrawer(true)} sx={{ color: '#fff', display: { md: 'none' }, ...focusOnBlue }}>
          <Menu />
        </IconButton>
        <Logo />
        <Box sx={{ flex: 1, order: { xs: 10, md: 0 }, flexBasis: { xs: '100%', md: 'auto' } }} role="search">
          <SearchAutocomplete />
        </Box>
        <Box sx={{ display: { xs: 'none', md: 'block' } }}><CepChip /></Box>
        <Box sx={{ ml: { xs: 'auto', md: 0 }, display: 'flex', alignItems: 'center', gap: 1 }}>
          <AccountButton />
          <CartButton />
        </Box>
        <Box sx={{ display: { xs: 'block', md: 'none' }, order: 11 }}><CepChip /></Box>
      </Box>
      <Box component="nav" aria-label="Categorias principais" sx={{ display: { xs: 'none', md: 'block' }, bgcolor: 'background.paper', borderBottom: 1, borderColor: 'divider' }}>
        <Box sx={{ maxWidth: maxContentWidth, mx: 'auto', px: 8, display: 'flex', alignItems: 'center', gap: 1, overflowX: 'auto' }}>
          <MegaMenu tree={cats} />
          {cats.slice(0, 10).map((c) => (
            <Button key={c.id} component={RouterLink} to={c.url_path} variant="text" size="medium" sx={{ color: 'text.primary', whiteSpace: 'nowrap' }}>
              {c.name}
            </Button>
          ))}
        </Box>
      </Box>
      <MobileDrawer open={drawer} onClose={() => setDrawer(false)} tree={cats} />
    </AppBar>
  );
}

export function WhatsAppFab() {
  const settings = useSettings();
  const wa = settings.data?.store.whatsapp;
  if (!wa) return null;
  const url = typeof window !== 'undefined' ? window.location.href : '';
  return (
    <IconButton
      component="a"
      href={`https://wa.me/55${wa}?text=${encodeURIComponent(`Olá! Vim pela loja: ${url}`)}`}
      target="_blank"
      rel="noopener"
      aria-label="Falar no WhatsApp"
      sx={{ position: 'fixed', right: 16, bottom: { xs: 96, md: 24 }, width: 56, height: 56, bgcolor: '#1B7F3B', color: '#fff', boxShadow: 6, zIndex: 9, '&:hover': { bgcolor: '#14602C' } }}
    >
      <WhatsApp />
    </IconButton>
  );
}
