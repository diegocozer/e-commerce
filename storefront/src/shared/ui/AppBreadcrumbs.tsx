import Breadcrumbs from '@mui/material/Breadcrumbs';
import Link from '@mui/material/Link';
import Typography from '@mui/material/Typography';
import { Link as RouterLink } from 'react-router';
import type { Breadcrumb } from '../api/types';

export function AppBreadcrumbs({ items }: { items: Breadcrumb[] }) {
  return (
    <Breadcrumbs aria-label="Trilha de navegação" sx={{ mb: 3, fontSize: 14, overflowX: 'auto', '& ol': { flexWrap: 'nowrap' } }}>
      {items.map((b, i) =>
        b.url_path && i < items.length - 1 ? (
          <Link key={`${b.name}-${i}`} component={RouterLink} to={b.url_path} color="inherit" sx={{ whiteSpace: 'nowrap' }}>
            {b.name}
          </Link>
        ) : (
          <Typography key={`${b.name}-${i}`} color="text.primary" aria-current="page" sx={{ fontSize: 14, whiteSpace: 'nowrap' }}>
            {b.name}
          </Typography>
        ),
      )}
    </Breadcrumbs>
  );
}
