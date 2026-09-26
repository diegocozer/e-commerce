import { Box, Grid, Typography } from '@mui/material';
import { useWatch, type Control } from 'react-hook-form';
import { FormSection, RHFTextField } from '@/shared/ui/form';
import type { ProductForm } from '../schemas/product';

const truncate = (s: string, n: number) => (s.length > n ? `${s.slice(0, n - 1).trimEnd()}…` : s);

/** Prévia de snippet do Google (UX §5.6 item 6). */
export function SnippetPreview({ title, description, path }: { title: string; description: string; path: string }) {
  const crumbs = path.split('/').filter(Boolean).join(' › ');
  return (
    <Box data-testid="google-snippet" sx={{ p: 4, border: 1, borderColor: 'divider', borderRadius: 2, bgcolor: 'background.paper', fontFamily: 'Arial, sans-serif', maxWidth: 600 }}>
      <Typography sx={{ fontSize: 12, color: 'text.secondary' }}>comunika.com.br › {crumbs}</Typography>
      <Typography sx={{ fontSize: 18, color: '#1a0dab', lineHeight: 1.3 }}>{truncate(title, 60)}</Typography>
      <Typography sx={{ fontSize: 13, color: 'text.secondary' }}>{truncate(description, 160)}</Typography>
    </Box>
  );
}

export function SeoSection({ control, urlPath, readOnly }: { control: Control<ProductForm>; urlPath: string; readOnly: boolean }) {
  const [name, metaTitle, metaDesc, shortDesc, slug] = useWatch({ control, name: ['name', 'meta_title', 'meta_description', 'short_description', 'slug'] });
  const title = metaTitle || `${name || 'Nome do produto'} | Comunika Suprimentos`;
  const desc = metaDesc || shortDesc || 'Descrição do produto.';
  const path = urlPath.replace(/[^/]+$/, slug || 'produto');
  return (
    <FormSection id="seo" title="SEO" description={!metaTitle && !metaDesc ? 'Vazio: usaremos o nome e a descrição do produto.' : undefined}>
      <Grid container spacing={4}>
        <Grid size={12}>
          <RHFTextField control={control} name="meta_title" label="Meta title" maxLength={120} helperText={metaTitle.length > 60 ? 'Acima de 60 caracteres o Google costuma truncar.' : 'Recomendado até 60 caracteres.'} disabled={readOnly} />
        </Grid>
        <Grid size={12}>
          <RHFTextField control={control} name="meta_description" label="Meta description" multiline minRows={2} maxLength={320} helperText={metaDesc.length > 160 ? 'Acima de 160 caracteres o Google costuma truncar.' : 'Recomendado até 160 caracteres.'} disabled={readOnly} />
        </Grid>
        <Grid size={12}>
          <Typography variant="overline" color="text.secondary">
            Prévia no Google
          </Typography>
          <SnippetPreview title={title} description={desc} path={path} />
        </Grid>
      </Grid>
    </FormSection>
  );
}
