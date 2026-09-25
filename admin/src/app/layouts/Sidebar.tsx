import ChevronLeftIcon from '@mui/icons-material/ChevronLeftOutlined';
import ChevronRightIcon from '@mui/icons-material/ChevronRightOutlined';
import { Badge, Box, Divider, List, ListItemButton, ListItemIcon, ListItemText, ListSubheader, Tooltip } from '@mui/material';
import { NavLink, useLocation } from 'react-router-dom';
import { useCanFn } from '@/shared/auth';
import { findNav, NAV } from '../navigation';
import { Logo } from './Logo';

interface Props {
  collapsed: boolean;
  onToggleCollapsed?: () => void;
  onNavigate?: () => void;
  toPick?: number;
}

/** Itens sem permissão não aparecem; grupo vazio some (UX §5.1). */
export function Sidebar({ collapsed, onToggleCollapsed, onNavigate, toPick }: Props) {
  const can = useCanFn();
  const { pathname } = useLocation();
  const active = findNav(pathname)?.item.to;
  const groups = NAV.map((g) => ({ ...g, items: g.items.filter((i) => can(i.perm)) })).filter((g) => g.items.length > 0);

  return (
    <Box sx={{ display: 'flex', flexDirection: 'column', height: '100%' }}>
      <Box sx={{ height: 56, display: 'flex', alignItems: 'center', px: collapsed ? 4 : 5 }}>
        <Logo compact={collapsed} />
      </Box>
      <Divider />
      <Box component="nav" aria-label="Menu principal" sx={{ flex: 1, overflowY: 'auto' }}>
        {groups.map((g) => (
          <List
            key={g.label}
            dense
            subheader={
              collapsed ? undefined : (
                <ListSubheader component="div" sx={{ lineHeight: '32px', fontSize: 11, fontWeight: 600, letterSpacing: '0.08em', textTransform: 'uppercase', bgcolor: 'transparent' }}>
                  {g.label}
                </ListSubheader>
              )
            }
          >
            {g.items.map((i) => {
              const isActive = active === i.to;
              const badge = i.badge === 'to_pick' && toPick ? toPick : 0;
              const btn = (
                <ListItemButton
                  key={i.to}
                  component={NavLink}
                  to={i.to}
                  end={i.to === '/'}
                  onClick={onNavigate}
                  selected={isActive}
                  aria-current={isActive ? 'page' : undefined}
                  sx={{
                    mx: 2,
                    borderRadius: 1,
                    borderLeft: 3,
                    borderColor: isActive ? 'primary.main' : 'transparent',
                    '&.Mui-selected': { bgcolor: 'primary.light', color: 'primary.main' },
                  }}
                >
                  <ListItemIcon sx={{ minWidth: 36, color: 'inherit' }}>
                    <Badge color="secondary" badgeContent={collapsed ? badge : 0} max={99}>
                      {i.icon}
                    </Badge>
                  </ListItemIcon>
                  {!collapsed && <ListItemText primary={i.label} />}
                  {!collapsed && badge > 0 && <Badge color="secondary" badgeContent={badge} max={999} sx={{ mr: 2 }} aria-label={`${badge} pedidos a separar`} />}
                </ListItemButton>
              );
              return collapsed ? (
                <Tooltip key={i.to} title={i.label} placement="right">
                  {btn}
                </Tooltip>
              ) : (
                btn
              );
            })}
          </List>
        ))}
      </Box>
      {onToggleCollapsed && (
        <>
          <Divider />
          <ListItemButton onClick={onToggleCollapsed} sx={{ py: 2 }} aria-label={collapsed ? 'Expandir menu' : 'Recolher menu'}>
            <ListItemIcon sx={{ minWidth: 36 }}>{collapsed ? <ChevronRightIcon /> : <ChevronLeftIcon />}</ListItemIcon>
            {!collapsed && <ListItemText primary="Recolher" />}
          </ListItemButton>
        </>
      )}
    </Box>
  );
}
