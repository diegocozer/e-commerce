import PixOutlined from '@mui/icons-material/PixOutlined';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Radio from '@mui/material/Radio';
import Typography from '@mui/material/Typography';
import { StepHeading } from './StepHeading';

export function PaymentStep({ expiryMinutes, onBack, onContinue }: { expiryMinutes: number; onBack: () => void; onContinue: () => void }) {
  return (
    <Box>
      <StepHeading>Pagamento</StepHeading>
      <Box component="label" sx={{ display: 'flex', gap: 2, p: 3, borderRadius: 2, border: 2, borderColor: 'primary.main', bgcolor: 'primary.light' }}>
        <Radio checked name="payment" value="pix" readOnly sx={{ p: 1 }} />
        <PixOutlined sx={{ color: 'primary.main', mt: 1 }} aria-hidden />
        <Box>
          <Typography sx={{ fontWeight: 600 }}>Pague com PIX — aprovação em segundos</Typography>
          <Typography variant="body2" color="text.secondary">
            O QR Code é gerado após confirmar o pedido e vale por {expiryMinutes} minutos.
          </Typography>
        </Box>
      </Box>
      <Box sx={{ display: 'flex', justifyContent: 'space-between', mt: 6 }}>
        <Button variant="outlined" onClick={onBack}>‹ Voltar</Button>
        <Button onClick={onContinue}>Continuar ›</Button>
      </Box>
    </Box>
  );
}
