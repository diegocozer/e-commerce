import LocalOfferOutlined from '@mui/icons-material/LocalOfferOutlined';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import TextField from '@mui/material/TextField';
import { useState, type FormEvent } from 'react';
import { toApiError } from '@/shared/api/errors';
import type { CartCoupon } from '@/shared/api/types';
import { formatBRL } from '@/shared/formatters/money';
import { useCartMutations } from '../hooks/useCart';

function couponLabel(c: CartCoupon): string {
  if (c.free_shipping) return `${c.code} — frete grátis`;
  return `${c.code} — ${c.description ?? `-${formatBRL(c.discount_cents)}`}`;
}

/** Cupom do carrinho (API §3.B PUT/DELETE /cart/coupon; 422 errors.code). */
export function CouponForm({ coupon }: { coupon: CartCoupon | null }) {
  const { applyCoupon, removeCoupon } = useCartMutations();
  const [code, setCode] = useState('');
  const [error, setError] = useState<string | null>(null);
  const submit = (e: FormEvent) => {
    e.preventDefault();
    if (code.trim().length < 3) {
      setError('Informe o código do cupom.');
      return;
    }
    setError(null);
    applyCoupon.mutate(code, {
      onSuccess: () => setCode(''),
      onError: (err) => {
        const ae = toApiError(err);
        setError(ae.fieldMessage('code') ?? ae.message);
      },
    });
  };
  if (coupon) {
    return (
      <Box sx={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
        <Chip
          icon={<LocalOfferOutlined />}
          label={couponLabel(coupon)}
          color={coupon.valid ? 'success' : 'default'}
          onDelete={() => removeCoupon.mutate()}
          aria-label={`Cupom ${coupon.code}`}
          sx={{ alignSelf: 'flex-start' }}
        />
        {!coupon.valid ? (
          <Alert severity={coupon.reason_code === 'login_required' ? 'info' : 'warning'}>
            {coupon.reason_code === 'login_required' ? 'Entre na sua conta para usar este cupom.' : (coupon.message ?? 'Cupom inválido ou expirado.')}
          </Alert>
        ) : null}
      </Box>
    );
  }
  return (
    <Box component="form" onSubmit={submit} sx={{ display: 'flex', gap: 2, alignItems: 'flex-start' }} noValidate>
      <TextField
        size="small"
        label="Cupom de desconto"
        value={code}
        onChange={(e) => setCode(e.target.value.toUpperCase())}
        error={Boolean(error)}
        helperText={error ?? undefined}
        slotProps={{ htmlInput: { maxLength: 40, autoCapitalize: 'characters' } }}
      />
      <Button type="submit" variant="outlined" size="medium" loading={applyCoupon.isPending}>
        Aplicar
      </Button>
    </Box>
  );
}
