import Box from '@mui/material/Box';
import Skeleton from '@mui/material/Skeleton';
import Typography from '@mui/material/Typography';
import { useQuery } from '@tanstack/react-query';
import { useParams } from 'react-router';
import { catalogKeys } from '@/features/catalog/api';
import { NotFoundPage } from '@/features/errors/pages/NotFoundPage';
import { getData } from '@/shared/api/client';
import { toApiError } from '@/shared/api/errors';
import type { InstitutionalPage as PageData } from '@/shared/api/types';
import { formatDate } from '@/shared/formatters/date';
import { ErrorState } from '@/shared/ui/ErrorState';
import { PageHeading } from '@/shared/ui/PageHeading';
import { Seo } from '@/shared/ui/Seo';

const SLUGS = ['sobre', 'termos', 'privacidade', 'trocas'];

/** /institucional/{slug} → GET /pages/{slug} (texto puro; parágrafos por \n\n). */
export default function InstitutionalPage() {
  const { slug = '' } = useParams();
  const q = useQuery({ queryKey: catalogKeys.page(slug), queryFn: () => getData<PageData>(`/pages/${slug}`), enabled: SLUGS.includes(slug), retry: false, staleTime: 10 * 60_000 });
  if (!SLUGS.includes(slug)) return <NotFoundPage />;
  if (q.isLoading) return <Box aria-busy="true"><Skeleton height={48} /><Skeleton height={200} /></Box>;
  if (q.error) return toApiError(q.error).status === 404 ? <NotFoundPage /> : <ErrorState error={q.error} onRetry={() => void q.refetch()} />;
  const p = q.data!;
  return (
    <Box sx={{ maxWidth: 760 }}>
      <Seo title={p.title} />
      <PageHeading>{p.title}</PageHeading>
      {p.body_text.split(/\n{2,}/).map((para, i) => (
        <Typography key={i} sx={{ mb: 3, whiteSpace: 'pre-line' }}>
          {para}
        </Typography>
      ))}
      {p.updated_at ? <Typography variant="caption" color="text.secondary">Atualizado em {formatDate(p.updated_at)}</Typography> : null}
    </Box>
  );
}
