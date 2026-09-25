import CategoryOutlined from '@mui/icons-material/CategoryOutlined';
import Box from '@mui/material/Box';
import type { ProductImage } from '../api/types';

/** Imagem 1:1 com srcset (300/800/1600 WebP) e placeholder quando ausente/processando. */
export function ProductImg({
  image,
  alt,
  sizes = '(min-width: 1200px) 25vw, 50vw',
  eager,
  size,
}: {
  image: ProductImage | null;
  alt: string;
  sizes?: string;
  eager?: boolean;
  size?: number;
}) {
  const box = { width: size ?? '100%', aspectRatio: '1 / 1', borderRadius: 2, bgcolor: '#fff', overflow: 'hidden', flexShrink: 0 } as const;
  if (!image?.urls) {
    return (
      <Box sx={{ ...box, display: 'grid', placeItems: 'center', bgcolor: 'grey.100', color: 'text.disabled' }} role="img" aria-label={alt}>
        <CategoryOutlined sx={{ fontSize: size ? size / 2 : 48 }} aria-hidden />
      </Box>
    );
  }
  return (
    <Box sx={box}>
      <img
        src={size && size <= 160 ? image.urls.w300 : image.urls.w800}
        srcSet={`${image.urls.w300} 300w, ${image.urls.w800} 800w, ${image.urls.w1600} 1600w`}
        sizes={size ? `${size}px` : sizes}
        alt={alt || image.alt}
        width={image.width ?? undefined}
        height={image.height ?? undefined}
        loading={eager ? 'eager' : 'lazy'}
        decoding="async"
        fetchPriority={eager ? 'high' : undefined}
        style={{ width: '100%', height: '100%', objectFit: 'contain', display: 'block' }}
      />
    </Box>
  );
}
