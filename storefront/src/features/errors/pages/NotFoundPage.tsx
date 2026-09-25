import SearchOutlined from '@mui/icons-material/SearchOutlined';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Link from '@mui/material/Link';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { useState, type FormEvent } from 'react';
import { Link as RouterLink, useNavigate } from 'react-router';
import { useCategoryTree } from '@/features/catalog/hooks/queries';
import { PageHeading } from '@/shared/ui/PageHeading';
import { Seo } from '@/shared/ui/Seo';

/** 404 na mesma URL, noindex (UX §3.1/§4.10). */
export function NotFoundPage({ message, category }: { message?: string; category?: { name: string; url_path: string } | null }) {
  const [q, setQ] = useState('');
  const navigate = useNavigate();
  const tree = useCategoryTree();
  const submit = (e: FormEvent) => {
    e.preventDefault();
    if (q.trim().length >= 2) navigate(`/busca?q=${encodeURIComponent(q.trim())}`);
  };
  return (
    <Box sx={{ textAlign: 'center', py: 10, maxWidth: 560, mx: 'auto' }}>
      <Seo title="Página não encontrada" robots="noindex,follow" />
      <SearchOutlined sx={{ fontSize: 64, color: 'primary.main' }} aria-hidden />
      <PageHeading>Página não encontrada</PageHeading>
      <Typography color="text.secondary" sx={{ mb: 4 }}>
        {message ?? 'O link pode estar errado ou o produto saiu de linha.'}
      </Typography>
      <Box component="form" role="search" onSubmit={submit} sx={{ display: 'flex', gap: 2, mb: 4 }}>
        <TextField label="Buscar por nome ou SKU" value={q} onChange={(e) => setQ(e.target.value)} />
        <Button type="submit" variant="outlined" aria-label="Buscar">
          <SearchOutlined />
        </Button>
      </Box>
      <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2} justifyContent="center" sx={{ mb: 4 }}>
        {category ? (
          <Button component={RouterLink} to={category.url_path} variant="outlined">
            Ver produtos de {category.name}
          </Button>
        ) : null}
        <Button component={RouterLink} to="/">
          Ir para a página inicial
        </Button>
      </Stack>
      {tree.data?.length ? (
        <Typography variant="body2">
          Categorias:{' '}
          {tree.data.map((c, i) => (
            <span key={c.id}>
              {i ? ' · ' : ''}
              <Link component={RouterLink} to={c.url_path}>
                {c.name}
              </Link>
            </span>
          ))}
        </Typography>
      ) : null}
    </Box>
  );
}

export default function NotFoundRoute() {
  return <NotFoundPage />;
}
