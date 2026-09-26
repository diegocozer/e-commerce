import ArrowBackIcon from '@mui/icons-material/ArrowBackIosNewOutlined';
import ArrowForwardIcon from '@mui/icons-material/ArrowForwardIosOutlined';
import DeleteIcon from '@mui/icons-material/DeleteOutlineOutlined';
import ImageIcon from '@mui/icons-material/ImageOutlined';
import UploadIcon from '@mui/icons-material/UploadFileOutlined';
import { Alert, Box, Button, Card, Chip, Grid, IconButton, LinearProgress, MenuItem, Stack, TextField, Typography } from '@mui/material';
import { useQueryClient } from '@tanstack/react-query';
import { useRef, useState, type DragEvent } from 'react';
import { errorMessage } from '@/shared/api/errors';
import type { AdminProduct, AdminProductImage } from '@/shared/api/types';
import { ConfirmDialog, notify } from '@/shared/ui';
import { FormSection } from '@/shared/ui/form';
import { deleteImage, productKeys, reorderImages, updateImage, uploadImage } from '../api';

const MAX_BYTES = 5 * 1024 * 1024;
const TYPES = ['image/jpeg', 'image/png', 'image/webp'];

interface Upload {
  key: string;
  name: string;
  status: 'uploading' | 'error';
  error?: string;
  file: File;
}

/** Imagens: upload múltiplo com progresso/retry, reordenação por botões (teclado), alt, variante, exclusão. */
export function ImagesSection({ product, readOnly }: { product: AdminProduct; readOnly: boolean }) {
  const qc = useQueryClient();
  const input = useRef<HTMLInputElement>(null);
  const [uploads, setUploads] = useState<Upload[]>([]);
  const [toDelete, setToDelete] = useState<AdminProductImage | null>(null);
  const images = [...product.images].sort((a, b) => a.position - b.position);
  const refresh = () => qc.invalidateQueries({ queryKey: productKeys.detail(product.id) });

  const send = async (u: Upload) => {
    setUploads((list) => [...list.filter((x) => x.key !== u.key), { ...u, status: 'uploading', error: undefined }]);
    try {
      await uploadImage(product.id, u.file);
      setUploads((list) => list.filter((x) => x.key !== u.key));
      await refresh();
    } catch (e) {
      setUploads((list) => list.map((x) => (x.key === u.key ? { ...x, status: 'error', error: errorMessage(e) } : x)));
    }
  };

  const addFiles = (files: FileList | File[]) => {
    Array.from(files).forEach((file, i) => {
      if (!TYPES.includes(file.type)) return notify.error(`${file.name}: use JPG, PNG ou WebP.`);
      if (file.size > MAX_BYTES) return notify.error(`${file.name}: máximo de 5 MB.`);
      if (images.length + uploads.length + i >= 10) return notify.error('Máximo de 10 imagens por produto.');
      void send({ key: `${file.name}-${Date.now()}-${i}`, name: file.name, status: 'uploading', file });
    });
  };

  const move = async (index: number, dir: -1 | 1) => {
    const ids = images.map((i) => i.id);
    const j = index + dir;
    [ids[index], ids[j]] = [ids[j], ids[index]];
    const prev = qc.getQueryData<AdminProduct>(productKeys.detail(product.id));
    // Reordenar pode ser otimista, com rollback (UX §6.9).
    if (prev) qc.setQueryData(productKeys.detail(product.id), { ...prev, images: ids.map((id, pos) => ({ ...prev.images.find((x) => x.id === id)!, position: pos })) });
    try {
      await reorderImages(product.id, ids);
    } catch (e) {
      if (prev) qc.setQueryData(productKeys.detail(product.id), prev);
      notify.error(errorMessage(e));
    }
  };

  const patch = async (img: AdminProductImage, body: { alt?: string; variant_id?: number | null }) => {
    try {
      await updateImage(product.id, img.id, body);
      await refresh();
      notify.success('Imagem atualizada');
    } catch (e) {
      notify.error(errorMessage(e));
    }
  };

  const onDrop = (e: DragEvent) => {
    e.preventDefault();
    if (!readOnly) addFiles(e.dataTransfer.files);
  };

  return (
    <FormSection id="imagens" title={`Imagens (${images.length}/10)`} description="JPG, PNG ou WebP até 5 MB (mínimo recomendado 800 × 800 px). A primeira é a principal.">
      {!readOnly && (
        <Box
          onDragOver={(e) => e.preventDefault()}
          onDrop={onDrop}
          sx={{ border: 2, borderStyle: 'dashed', borderColor: 'divider', borderRadius: 2, p: 6, textAlign: 'center', mb: 4 }}
        >
          <UploadIcon sx={{ fontSize: 36, color: 'text.secondary' }} aria-hidden />
          <Typography variant="body2" sx={{ my: 2 }}>
            Arraste imagens aqui ou
          </Typography>
          <Button variant="outlined" onClick={() => input.current?.click()}>
            Selecionar arquivos
          </Button>
          <input ref={input} type="file" accept={TYPES.join(',')} multiple hidden aria-label="Selecionar imagens" onChange={(e) => e.target.files && addFiles(e.target.files)} data-testid="image-input" />
        </Box>
      )}
      {uploads.map((u) => (
        <Stack key={u.key} direction="row" spacing={2} sx={{ alignItems: 'center', mb: 2 }}>
          <Typography variant="body2" sx={{ minWidth: 160 }} noWrap>
            {u.name}
          </Typography>
          {u.status === 'uploading' ? (
            <LinearProgress sx={{ flex: 1 }} aria-label={`Enviando ${u.name}`} />
          ) : (
            <Alert severity="error" sx={{ flex: 1 }} action={<Button color="inherit" size="small" onClick={() => void send(u)}>Tentar novamente</Button>}>
              {u.error}
            </Alert>
          )}
        </Stack>
      ))}
      <Grid container spacing={3}>
        {images.map((img, idx) => (
          <Grid key={img.id} size={{ xs: 12, sm: 6, md: 4, lg: 3 }}>
            <Card>
              <Box sx={{ aspectRatio: '1 / 1', bgcolor: 'action.hover', display: 'grid', placeItems: 'center', position: 'relative' }}>
                {img.urls ? (
                  <img src={img.urls.w300} alt={img.alt} width={img.width ?? undefined} height={img.height ?? undefined} loading="lazy" style={{ width: '100%', height: '100%', objectFit: 'contain' }} />
                ) : (
                  <Stack sx={{ alignItems: 'center' }}>
                    <ImageIcon color="disabled" />
                    <Typography variant="caption">Processando…</Typography>
                  </Stack>
                )}
                {idx === 0 && <Chip label="Principal" color="primary" size="small" sx={{ position: 'absolute', top: 8, left: 8 }} />}
              </Box>
              <Stack spacing={2} sx={{ p: 2 }}>
                <TextField
                  label="Texto alternativo"
                  defaultValue={img.alt}
                  disabled={readOnly}
                  onBlur={(e) => e.target.value !== img.alt && void patch(img, { alt: e.target.value })}
                  slotProps={{ htmlInput: { maxLength: 255 } }}
                  helperText={!img.alt ? 'Obrigatório para publicar' : undefined}
                />
                {product.variants.length > 1 && (
                  <TextField select label="Variante" value={img.variant_id ?? ''} disabled={readOnly} onChange={(e) => void patch(img, { variant_id: e.target.value === '' ? null : Number(e.target.value) })}>
                    <MenuItem value="">Todas</MenuItem>
                    {product.variants.map((v) => (
                      <MenuItem key={v.id} value={v.id}>
                        {v.name} ({v.sku})
                      </MenuItem>
                    ))}
                  </TextField>
                )}
                {!readOnly && (
                  <Stack direction="row" sx={{ justifyContent: 'space-between' }}>
                    <IconButton aria-label={`Mover imagem ${idx + 1} para a esquerda`} disabled={idx === 0} onClick={() => void move(idx, -1)}>
                      <ArrowBackIcon fontSize="small" />
                    </IconButton>
                    <IconButton aria-label={`Excluir imagem ${idx + 1}`} onClick={() => setToDelete(img)}>
                      <DeleteIcon />
                    </IconButton>
                    <IconButton aria-label={`Mover imagem ${idx + 1} para a direita`} disabled={idx === images.length - 1} onClick={() => void move(idx, 1)}>
                      <ArrowForwardIcon fontSize="small" />
                    </IconButton>
                  </Stack>
                )}
              </Stack>
            </Card>
          </Grid>
        ))}
      </Grid>
      <ConfirmDialog
        open={!!toDelete}
        title="Excluir esta imagem?"
        description="A imagem será removida do produto."
        confirmLabel="Excluir imagem"
        destructive
        onClose={() => setToDelete(null)}
        onConfirm={async () => {
          const img = toDelete!;
          setToDelete(null);
          try {
            await deleteImage(product.id, img.id);
            await refresh();
            notify.success('Imagem excluída');
          } catch (e) {
            notify.error(errorMessage(e));
          }
        }}
      />
    </FormSection>
  );
}
