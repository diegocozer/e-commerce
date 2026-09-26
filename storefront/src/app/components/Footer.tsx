import Box from '@mui/material/Box';
import Grid from '@mui/material/Grid';
import Link from '@mui/material/Link';
import Typography from '@mui/material/Typography';
import { Link as RouterLink } from 'react-router';
import { useSettings } from '@/features/catalog/hooks/queries';
import { formatCEP } from '@/shared/formatters/postalCode';
import { formatPhone } from '@/shared/formatters/phone';
import { colors, maxContentWidth } from '../theme/tokens';
import { Logo } from './Logo';

const linkSx = { color: colors.footerText, display: 'block', py: 0.5 } as const;

/** Rodapé (UX §3.3) — dados vêm das configurações públicas. */
export function Footer({ minimal }: { minimal?: boolean }) {
  const s = useSettings().data;
  const store = s?.store;
  const pickup = s?.pickup_points[0];
  return (
    <Box component="footer" sx={{ bgcolor: 'footer', color: colors.footerText, mt: 12, py: 8 }}>
      <Box sx={{ maxWidth: maxContentWidth, mx: 'auto', px: { xs: 4, md: 8 } }}>
        {!minimal ? (
          <>
            <Logo color={colors.footerText} />
            <Typography sx={{ mt: 2, mb: 6 }}>Suprimentos para comunicação visual, do jeito que você compra.</Typography>
            <Grid container spacing={6}>
              <Grid size={{ xs: 12, sm: 6, md: 3 }}>
                <Typography variant="overline" component="h2">Institucional</Typography>
                <Link component={RouterLink} to="/institucional/sobre" sx={linkSx}>Sobre nós</Link>
                <Link component={RouterLink} to="/institucional/trocas" sx={linkSx}>Trocas e devoluções</Link>
                <Link component={RouterLink} to="/institucional/privacidade" sx={linkSx}>Política de privacidade</Link>
                <Link component={RouterLink} to="/institucional/termos" sx={linkSx}>Termos de uso</Link>
              </Grid>
              <Grid size={{ xs: 12, sm: 6, md: 3 }}>
                <Typography variant="overline" component="h2">Atendimento</Typography>
                {store?.phone ? <Typography>{formatPhone(store.phone)}</Typography> : null}
                {store?.whatsapp ? <Link href={`https://wa.me/55${store.whatsapp}`} target="_blank" rel="noopener" sx={linkSx}>WhatsApp {formatPhone(store.whatsapp)}</Link> : null}
                {store?.email ? <Link href={`mailto:${store.email}`} sx={linkSx}>{store.email}</Link> : null}
                {store?.opening_hours ? <Typography variant="body2">{store.opening_hours}</Typography> : null}
              </Grid>
              <Grid size={{ xs: 12, sm: 6, md: 3 }}>
                <Typography variant="overline" component="h2">Compra segura</Typography>
                <Typography>Pagamento via PIX</Typography>
                <Typography variant="body2">(cartão e boleto em breve)</Typography>
              </Grid>
              <Grid size={{ xs: 12, sm: 6, md: 3 }}>
                <Typography variant="overline" component="h2">Entrega</Typography>
                {pickup ? (
                  <Typography variant="body2">
                    Retirada grátis na loja: {pickup.street}, {pickup.number} – {pickup.city}/{pickup.state} – {formatCEP(pickup.postal_code)}
                    {pickup.opening_hours ? ` (${pickup.opening_hours})` : ''}
                  </Typography>
                ) : null}
                <Typography variant="body2">Entrega própria na região · Transportadoras para o Brasil</Typography>
                {s?.free_shipping_banner?.enabled ? <Typography variant="body2">{s.free_shipping_banner.text}</Typography> : null}
              </Grid>
            </Grid>
          </>
        ) : null}
        <Typography variant="caption" component="p" sx={{ mt: minimal ? 0 : 8, opacity: 0.85 }}>
          {store?.name ?? 'Comunika Suprimentos'} · Seus dados são tratados conforme a LGPD.{' '}
          <Link component={RouterLink} to="/institucional/privacidade" sx={{ color: colors.footerText }}>Política de privacidade</Link>
        </Typography>
      </Box>
    </Box>
  );
}
