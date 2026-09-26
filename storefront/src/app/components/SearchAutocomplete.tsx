import SearchOutlined from '@mui/icons-material/SearchOutlined';
import Autocomplete from '@mui/material/Autocomplete';
import Box from '@mui/material/Box';
import Chip from '@mui/material/Chip';
import InputAdornment from '@mui/material/InputAdornment';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useNavigate } from 'react-router';
import { catalogKeys, fetchAutocomplete } from '@/features/catalog/api';
import { formatBRL } from '@/shared/formatters/money';
import { useDebounce } from '@/shared/hooks/useDebounce';
import { NBSP } from '@/shared/saleUnit/labels';

type Option =
  | { kind: 'product'; label: string; url: string; sku: string | null; price: number; abbr: string; image: string | null; group: string }
  | { kind: 'category'; label: string; url: string; group: string }
  | { kind: 'all'; label: string; url: string; group: string };

function Highlight({ text, term }: { text: string; term: string }) {
  const i = term ? text.toLowerCase().indexOf(term.toLowerCase()) : -1;
  if (i < 0) return <>{text}</>;
  return (
    <>
      {text.slice(0, i)}
      <strong>{text.slice(i, i + term.length)}</strong>
      {text.slice(i + term.length)}
    </>
  );
}

/** Busca com autocomplete (UX §3.2): 2+ caracteres, debounce 250 ms, cancela a anterior. */
export function SearchAutocomplete({ onDone }: { onDone?: () => void }) {
  const [input, setInput] = useState('');
  const navigate = useNavigate();
  const term = useDebounce(input.trim(), 250);
  const q = useQuery({
    queryKey: catalogKeys.autocomplete(term),
    queryFn: ({ signal }) => fetchAutocomplete(term, signal),
    enabled: term.length >= 2,
    staleTime: 60_000,
    retry: false,
  });
  const looksLikeSku = /\d|-/.test(term) && !/\s/.test(term);
  const products = [...(q.data?.products ?? [])].sort((a, b) => (looksLikeSku ? Number(Boolean(b.matched_sku)) - Number(Boolean(a.matched_sku)) : 0));
  const options: Option[] = term.length >= 2 && q.data
    ? [
        ...products.slice(0, 6).map<Option>((p) => ({ kind: 'product', label: p.name, url: p.matched_sku ? `${p.url_path}?sku=${encodeURIComponent(p.matched_sku)}` : p.url_path, sku: p.matched_sku, price: p.unit_price_cents, abbr: p.sale_unit_abbr, image: p.image_url, group: 'Produtos' })),
        ...q.data.categories.slice(0, 3).map<Option>((c) => ({ kind: 'category', label: c.name, url: c.url_path, group: 'Categorias' })),
        { kind: 'all', label: `Ver todos os resultados para "${term}"`, url: `/busca?q=${encodeURIComponent(term)}`, group: ' ' },
      ]
    : [];

  const go = (url: string) => {
    navigate(url);
    setInput('');
    onDone?.();
  };

  return (
    <>
      <Autocomplete<Option, false, false, true>
        freeSolo
        fullWidth
        options={options}
        filterOptions={(o) => o}
        groupBy={(o) => o.group}
        getOptionLabel={(o) => (typeof o === 'string' ? o : o.label)}
        inputValue={input}
        onInputChange={(_, v, reason) => reason !== 'reset' && setInput(v)}
        loading={q.isFetching}
        loadingText="Buscando…"
        noOptionsText={term.length < 2 ? 'Digite pelo menos 2 caracteres' : 'Nenhuma sugestão'}
        onChange={(_, value) => {
          if (!value) return;
          if (typeof value === 'string') {
            const t = value.trim();
            if (t.length >= 2) go(`/busca?q=${encodeURIComponent(t)}`);
          } else go(value.url);
        }}
        renderOption={(props, o) => {
          const { key, ...rest } = props as typeof props & { key: string };
          return (
            <Box component="li" key={key} {...rest} sx={{ display: 'flex', gap: 2, alignItems: 'center' }}>
              {o.kind === 'product' ? (
                <>
                  {o.image ? <img src={o.image} alt="" width={40} height={40} style={{ objectFit: 'contain' }} /> : <Box sx={{ width: 40, height: 40, bgcolor: 'grey.100', borderRadius: 1 }} />}
                  <Box sx={{ flex: 1, minWidth: 0 }}>
                    <Typography variant="body2"><Highlight text={o.label} term={term} /></Typography>
                    {o.sku ? <Typography variant="caption" color="text.secondary">SKU {o.sku} <Chip size="small" label="SKU" sx={{ height: 18 }} /></Typography> : null}
                  </Box>
                  <Typography variant="body2" className="num">{formatBRL(o.price)}{NBSP}/{o.abbr}</Typography>
                </>
              ) : (
                <Typography variant="body2" sx={{ fontWeight: o.kind === 'all' ? 600 : 400 }}>
                  <Highlight text={o.label} term={o.kind === 'all' ? '' : term} />
                </Typography>
              )}
            </Box>
          );
        }}
        renderInput={(params) => (
          <TextField
            {...params}
            placeholder="Buscar por nome ou SKU…"
            size="small"
            sx={{ bgcolor: 'background.paper', borderRadius: 2, '& .MuiOutlinedInput-root': { borderRadius: 2 } }}
            slotProps={{
              ...params.slotProps,
              htmlInput: { ...params.slotProps.htmlInput, 'aria-label': 'Buscar produtos por nome ou SKU', enterKeyHint: 'search' },
              input: { ...params.slotProps.input, startAdornment: <InputAdornment position="start"><SearchOutlined aria-hidden /></InputAdornment> },
            }}
          />
        )}
      />
      <span className="visually-hidden" aria-live="polite">
        {options.length ? `${options.length - 1} sugestões disponíveis` : ''}
      </span>
    </>
  );
}
