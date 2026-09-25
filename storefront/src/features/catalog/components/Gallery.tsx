import Close from '@mui/icons-material/Close';
import Box from '@mui/material/Box';
import ButtonBase from '@mui/material/ButtonBase';
import Dialog from '@mui/material/Dialog';
import IconButton from '@mui/material/IconButton';
import { useState } from 'react';
import type { ProductImage } from '@/shared/api/types';
import { ProductImg } from '@/shared/ui/ProductImg';

/** Galeria (UX §4.4.2): miniaturas, toque/clique abre zoom em tela cheia. */
export function Gallery({ images, name }: { images: ProductImage[]; name: string }) {
  const [index, setIndex] = useState(0);
  const [zoom, setZoom] = useState(false);
  const current = images[Math.min(index, images.length - 1)] ?? null;
  const altFor = (i: number) => `${name} — foto ${i + 1} de ${Math.max(images.length, 1)}`;
  return (
    <Box sx={{ display: 'flex', flexDirection: { xs: 'column', md: 'row-reverse' }, gap: 3 }}>
      <ButtonBase onClick={() => current && setZoom(true)} sx={{ flex: 1, borderRadius: 2, display: 'block' }} aria-label={current ? `Ampliar ${altFor(index)}` : name} disabled={!current}>
        <ProductImg image={current} alt={altFor(index)} eager sizes="(min-width: 900px) 55vw, 100vw" />
      </ButtonBase>
      {images.length > 1 ? (
        <Box component="ul" aria-label="Fotos do produto" sx={{ listStyle: 'none', p: 0, m: 0, display: 'flex', flexDirection: { xs: 'row', md: 'column' }, gap: 2, overflowX: 'auto' }}>
          {images.map((img, i) => (
            <li key={img.id}>
              <ButtonBase
                onClick={() => setIndex(i)}
                aria-label={`Ver ${altFor(i)}`}
                aria-current={i === index ? 'true' : undefined}
                sx={{ borderRadius: 2, border: 2, borderColor: i === index ? 'primary.main' : 'transparent' }}
              >
                <ProductImg image={img} alt="" size={64} />
              </ButtonBase>
            </li>
          ))}
        </Box>
      ) : null}
      <Dialog fullScreen open={zoom} onClose={() => setZoom(false)} aria-label={`Zoom: ${altFor(index)}`}>
        <IconButton aria-label="Fechar zoom" onClick={() => setZoom(false)} sx={{ position: 'absolute', right: 8, top: 8, zIndex: 1 }}>
          <Close />
        </IconButton>
        <Box sx={{ width: '100%', height: '100%', overflow: 'auto', display: 'grid', placeItems: 'center', touchAction: 'pinch-zoom' }}>
          {current?.urls ? <img src={current.urls.w1600} alt={altFor(index)} style={{ maxWidth: '100%', height: 'auto' }} /> : null}
        </Box>
      </Dialog>
    </Box>
  );
}
