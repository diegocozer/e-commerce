import LocalShippingOutlined from '@mui/icons-material/LocalShippingOutlined';
import StorefrontOutlined from '@mui/icons-material/StorefrontOutlined';
import Box from '@mui/material/Box';
import Radio from '@mui/material/Radio';
import Typography from '@mui/material/Typography';
import type { ShippingOption } from '../api/types';
import { formatBRL } from '../formatters/money';
import { formatCEP } from '../formatters/postalCode';

function OptionPrice({ option }: { option: ShippingOption }) {
  if (option.price_cents === 0) {
    return (
      <Typography component="span" sx={{ color: 'success.main', fontWeight: 700 }}>
        Grátis
      </Typography>
    );
  }
  return (
    <Typography component="span" className="num" sx={{ fontWeight: 700 }}>
      {formatBRL(option.price_cents)}
    </Typography>
  );
}

function OptionBody({ option }: { option: ShippingOption }) {
  const Icon = option.method_type === 'pickup' ? StorefrontOutlined : LocalShippingOutlined;
  const pa = option.pickup_address;
  return (
    <Box sx={{ display: 'flex', gap: 3, alignItems: 'flex-start', flex: 1, minWidth: 0 }}>
      <Icon aria-hidden sx={{ mt: 0.5, color: 'primary.main' }} />
      <Box sx={{ flex: 1, minWidth: 0 }}>
        <Box sx={{ display: 'flex', justifyContent: 'space-between', gap: 2 }}>
          <Typography component="span" sx={{ fontWeight: 600 }}>
            {option.name}
          </Typography>
          <OptionPrice option={option} />
        </Box>
        <Typography variant="body2" color="text.secondary">
          {option.delivery_label}
        </Typography>
        {pa ? (
          <Typography variant="caption" color="text.secondary" component="p">
            {pa.street}, {pa.number} – {pa.district} – {pa.city}/{pa.state} – {formatCEP(pa.postal_code)}
            {pa.opening_hours ? ` · ${pa.opening_hours}` : ''}
          </Typography>
        ) : null}
      </Box>
    </Box>
  );
}

/** Lista simples (estimativa no produto). */
export function ShippingOptionList({ options }: { options: ShippingOption[] }) {
  return (
    <Box component="ul" sx={{ listStyle: 'none', p: 0, m: 0, border: 1, borderColor: 'divider', borderRadius: 2 }}>
      {options.map((o, i) => (
        <Box component="li" key={o.option_id} sx={{ p: 3, borderTop: i ? 1 : 0, borderColor: 'divider' }}>
          <OptionBody option={o} />
        </Box>
      ))}
    </Box>
  );
}

/** Radio cards (carrinho/checkout) — input radio real dentro do label (UX §6.10). */
export function ShippingOptionRadios({
  name,
  options,
  value,
  onChange,
  legend,
}: {
  name: string;
  options: ShippingOption[];
  value: string | null;
  onChange: (optionId: string) => void;
  legend: string;
}) {
  return (
    <Box component="fieldset" sx={{ border: 0, p: 0, m: 0, display: 'flex', flexDirection: 'column', gap: 2 }}>
      <legend className="visually-hidden">{legend}</legend>
      {options.map((o) => {
        const selected = value === o.option_id;
        return (
          <Box
            component="label"
            key={o.option_id}
            sx={{
              display: 'flex', alignItems: 'flex-start', gap: 1, p: 3, cursor: 'pointer', borderRadius: 2,
              border: selected ? 2 : 1, borderColor: selected ? 'primary.main' : 'border.input',
              bgcolor: selected ? 'primary.light' : 'background.paper',
            }}
          >
            <Radio name={name} value={o.option_id} checked={selected} onChange={() => onChange(o.option_id)} sx={{ p: 1 }} />
            <OptionBody option={o} />
          </Box>
        );
      })}
    </Box>
  );
}
