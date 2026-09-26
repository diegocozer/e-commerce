import AddIcon from '@mui/icons-material/AddOutlined';
import DeleteIcon from '@mui/icons-material/DeleteOutlineOutlined';
import { Alert, Autocomplete, Button, Grid, IconButton, Stack, TextField, Typography } from '@mui/material';
import { Controller, useFieldArray, type Control, type UseFormSetError, type UseFormSetValue } from 'react-hook-form';
import { flattenCategories, useBrandOptions, useCategoryTree } from '@/shared/api/lookups';
import { FormSection, RHFSelect, RHFSwitch, RHFTextField } from '@/shared/ui/form';
import { checkSlug } from '../api';
import { slugify, type ProductForm } from '../schemas/product';

interface Props {
  control: Control<ProductForm>;
  setValue: UseFormSetValue<ProductForm>;
  setError: UseFormSetError<ProductForm>;
  productId: number | null;
  slugTouched: boolean;
  onSlugTouched: () => void;
  urlPath: string;
  activationIssues: string[];
  readOnly: boolean;
}

export function GeneralSection({ control, setValue, setError, productId, slugTouched, onSlugTouched, urlPath, activationIssues, readOnly }: Props) {
  const cats = flattenCategories(useCategoryTree().data);
  const brands = useBrandOptions().data ?? [];
  const specs = useFieldArray({ control, name: 'specifications' });
  return (
    <FormSection id="geral" title="Informações gerais">
      <Grid container spacing={4}>
        <Grid size={{ xs: 12, md: 8 }}>
          <RHFTextField
            control={control}
            name="name"
            label="Nome"
            required
            maxLength={150}
            disabled={readOnly}
            onValueChange={(v) => {
              if (!slugTouched && productId === null) setValue('slug', slugify(v), { shouldDirty: true });
            }}
          />
        </Grid>
        <Grid size={{ xs: 12, md: 4 }}>
          <Controller
            control={control}
            name="slug"
            render={({ field, fieldState }) => (
              <TextField
                {...field}
                label="Slug (endereço)"
                disabled={readOnly}
                onChange={(e) => {
                  onSlugTouched();
                  field.onChange(e.target.value.toLowerCase());
                }}
                onBlur={async () => {
                  field.onBlur();
                  if (!field.value) return;
                  try {
                    const r = await checkSlug(field.value, productId);
                    if (!r.available) setError('slug', { type: 'server', message: `Endereço em uso.${r.suggestion ? ` Sugestão: ${r.suggestion}` : ''}` });
                  } catch {
                    /* a API valida novamente no salvar */
                  }
                }}
                error={!!fieldState.error}
                helperText={fieldState.error?.message ?? `Prévia: …${urlPath.replace(/[^/]+$/, '')}${field.value || '(gerado do nome)'}`}
              />
            )}
          />
        </Grid>
        <Grid size={{ xs: 12, md: 6 }}>
          <RHFSelect control={control} name="primary_category_id" label="Categoria principal" required disabled={readOnly} options={cats.map((c) => ({ value: c.id, label: c.is_active ? c.label : `${c.label} (inativa)` }))} />
        </Grid>
        <Grid size={{ xs: 12, md: 6 }}>
          <RHFSelect control={control} name="brand_id" label="Marca" emptyLabel="Sem marca" disabled={readOnly} options={brands.map((b) => ({ value: b.id, label: b.name }))} />
        </Grid>
        <Grid size={12}>
          <Controller
            control={control}
            name="category_ids"
            render={({ field }) => (
              <Autocomplete
                multiple
                disabled={readOnly}
                options={cats.map((c) => c.id)}
                value={field.value}
                onChange={(_, v) => field.onChange(v)}
                getOptionLabel={(id) => cats.find((c) => c.id === id)?.label ?? String(id)}
                renderInput={(params) => <TextField {...params} label="Categorias adicionais" helperText="A categoria principal já é incluída automaticamente." />}
              />
            )}
          />
        </Grid>
        <Grid size={12}>
          <RHFTextField control={control} name="short_description" label="Descrição curta" multiline minRows={2} maxLength={500} disabled={readOnly} />
        </Grid>
        <Grid size={12}>
          <RHFTextField
            control={control}
            name="description_html"
            label="Descrição (HTML simples: <p>, <strong>, <ul>, <li>, <a>)"
            multiline
            minRows={5}
            disabled={readOnly}
            helperText="O conteúdo é sanitizado no servidor."
          />
        </Grid>
        <Grid size={12}>
          <Typography variant="h4" component="h3" sx={{ mb: 2 }}>
            Especificações técnicas
          </Typography>
          <Stack spacing={2}>
            {specs.fields.map((f, i) => (
              <Stack key={f.id} direction="row" spacing={2} sx={{ alignItems: 'flex-start' }}>
                <RHFTextField control={control} name={`specifications.${i}.label`} label="Característica" disabled={readOnly} />
                <RHFTextField control={control} name={`specifications.${i}.value`} label="Valor" disabled={readOnly} />
                {!readOnly && (
                  <IconButton aria-label={`Remover especificação ${i + 1}`} onClick={() => specs.remove(i)}>
                    <DeleteIcon />
                  </IconButton>
                )}
              </Stack>
            ))}
            {!readOnly && specs.fields.length < 30 && (
              <Button startIcon={<AddIcon />} onClick={() => specs.append({ label: '', value: '' })} sx={{ alignSelf: 'flex-start' }}>
                Adicionar especificação
              </Button>
            )}
          </Stack>
        </Grid>
        <Grid size={12}>
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={4}>
            <RHFSwitch control={control} name="is_active" label="Ativo (visível na loja)" disabled={readOnly} />
            <RHFSwitch control={control} name="is_featured" label="Destaque" disabled={readOnly} />
            <RHFSwitch control={control} name="pickup_only" label="Somente retirada" disabled={readOnly} />
          </Stack>
          {activationIssues.length > 0 && (
            <Alert severity="warning" sx={{ mt: 2 }}>
              Para ativar este produto: {activationIssues.join(' · ')}
            </Alert>
          )}
        </Grid>
      </Grid>
    </FormSection>
  );
}
