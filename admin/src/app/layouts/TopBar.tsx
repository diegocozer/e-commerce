import AccountCircleIcon from '@mui/icons-material/AccountCircleOutlined';
import DarkModeIcon from '@mui/icons-material/DarkModeOutlined';
import LightModeIcon from '@mui/icons-material/LightModeOutlined';
import MenuIcon from '@mui/icons-material/MenuOutlined';
import { AppBar, Breadcrumbs, Button, Chip, Divider, IconButton, Link, ListItemIcon, Menu, MenuItem, Toolbar, Tooltip, Typography } from '@mui/material';
import { useColorScheme } from '@mui/material/styles';
import { useState } from 'react';
import { Link as RouterLink, useLocation, useNavigate } from 'react-router-dom';
import { useLogout } from '@/features/auth/api';
import { useMe } from '@/shared/auth';
import { useBreadcrumbLabel } from '../breadcrumb';
import { findNav } from '../navigation';
import { GlobalSearch } from './GlobalSearch';

export function TopBar({ onMenu }: { onMenu: () => void }) {
  const { data: me } = useMe();
  const { pathname } = useLocation();
  const navigate = useNavigate();
  const nav = findNav(pathname);
  const extra = useBreadcrumbLabel();
  const { mode, systemMode, setMode } = useColorScheme();
  const effective = mode === 'system' ? systemMode : mode;
  const [anchor, setAnchor] = useState<HTMLElement | null>(null);
  const logout = useLogout();
  const envLabel = import.meta.env.VITE_APP_ENV_LABEL as string | undefined;

  return (
    <AppBar position="sticky" color="inherit" elevation={0} sx={{ borderBottom: 1, borderColor: 'divider', bgcolor: 'background.paper' }}>
      <Toolbar sx={{ gap: 2, minHeight: '56px !important' }}>
        <IconButton onClick={onMenu} aria-label="Abrir menu" sx={{ display: { lg: 'none' } }}>
          <MenuIcon />
        </IconButton>
        <Breadcrumbs aria-label="Trilha de navegação" sx={{ flex: 1, minWidth: 0, '& ol': { flexWrap: 'nowrap' } }}>
          {nav && <Typography variant="body2" color="text.secondary">{nav.group.label}</Typography>}
          {nav && (extra || pathname !== nav.item.to) ? (
            <Link component={RouterLink} to={nav.item.to} variant="body2">
              {nav.item.label}
            </Link>
          ) : (
            nav && <Typography variant="body2" color="text.primary" aria-current="page">{nav.item.label}</Typography>
          )}
          {nav && extra && <Typography variant="body2" color="text.primary" aria-current="page" noWrap>{extra}</Typography>}
        </Breadcrumbs>
        {envLabel && <Chip label={envLabel} size="small" sx={{ bgcolor: '#FFE082', color: '#3E2723' }} />}
        <GlobalSearch />
        <Tooltip title={effective === 'dark' ? 'Usar tema claro' : 'Usar tema escuro'}>
          <IconButton onClick={() => setMode(effective === 'dark' ? 'light' : 'dark')} aria-label={effective === 'dark' ? 'Usar tema claro' : 'Usar tema escuro'}>
            {effective === 'dark' ? <LightModeIcon /> : <DarkModeIcon />}
          </IconButton>
        </Tooltip>
        <Button color="inherit" onClick={(e) => setAnchor(e.currentTarget)} startIcon={<AccountCircleIcon />} aria-haspopup="menu" aria-label="Menu do usuário">
          <Typography variant="body2" noWrap sx={{ maxWidth: 140, display: { xs: 'none', sm: 'block' } }}>
            {me?.user.name}
          </Typography>
        </Button>
        <Menu anchorEl={anchor} open={!!anchor} onClose={() => setAnchor(null)}>
          <MenuItem disabled>
            <Typography variant="caption">{me?.user.email}</Typography>
          </MenuItem>
          <Divider />
          <MenuItem onClick={() => { setAnchor(null); navigate('/conta/senha'); }}>Alterar senha</MenuItem>
          <MenuItem onClick={() => { setMode('system'); setAnchor(null); }}>
            <ListItemIcon />
            Tema do sistema {mode === 'system' ? '✓' : ''}
          </MenuItem>
          <Divider />
          <MenuItem
            onClick={() => {
              setAnchor(null);
              logout.mutate(undefined, { onSettled: () => navigate('/entrar', { replace: true }) });
            }}
          >
            Sair
          </MenuItem>
        </Menu>
      </Toolbar>
    </AppBar>
  );
}
