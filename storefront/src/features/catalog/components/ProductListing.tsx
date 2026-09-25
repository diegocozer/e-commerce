import GridViewOutlined from '@mui/icons-material/GridViewOutlined';
import SearchOutlined from '@mui/icons-material/SearchOutlined';
import TuneOutlined from '@mui/icons-material/TuneOutlined';
import ViewListOutlined from '@mui/icons-material/ViewListOutlined';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Checkbox from '@mui/material/Checkbox';
import Chip from '@mui/material/Chip';
import Drawer from '@mui/material/Drawer';
import FormControl from '@mui/material/FormControl';
import FormControlLabel from '@mui/material/FormControlLabel';
import InputLabel from '@mui/material/InputLabel';
import LinearProgress from '@mui/material/LinearProgress';
import MenuItem from '@mui/material/MenuItem';
import Pagination from '@mui/material/Pagination';
import Radio from '@mui/material/Radio';
import RadioGroup from '@mui/material/RadioGroup';
import Select from '@mui/material/Select';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import ToggleButton from '@mui/material/ToggleButton';
import ToggleButtonGroup from '@mui/material/ToggleButtonGroup';
import Typography from '@mui/material/Typography';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import type { CategoryNode, ProductFacets, ProductListResponse, SaleUnit } from '@/shared/api/types';
import { EmptyState } from '@/shared/ui/EmptyState';
import { ErrorState } from '@/shared/ui/ErrorState';
import { SALE_UNIT_LABEL } from '@/shared/saleUnit/labels';
import { useQuickAdd } from '../hooks/useQuickAdd';
import { SORT_OPTIONS, useListingParams, type ListingState } from '../hooks/useListingParams';
import { ProductCardSkeleton, ProductCardView } from './ProductCardView';

interface Props {
  mode: 'category' | 'search';
  data: ProductListResponse | undefined;
  isLoading: boolean;
  isFetching: boolean;
  error: unknown;
  onRetry: () => void;
  subcategories?: CategoryNode[];
  emptyContent?: ReactNode;
}

type Update = ReturnType<typeof useListingParams>['update'];

function toggle(list: string[], value: string): string | null {
  const next = list.includes(value) ? list.filter((v) => v !== value) : [...list, value];
  return next.length ? next.join(',') : null;
}

function FilterPanel({ mode, state, update, facets, subcategories }: { mode: Props['mode']; state: ListingState; update: Update; facets?: ProductFacets; subcategories?: CategoryNode[] }) {
  const [showAllBrands, setShowAllBrands] = useState(false);
  const [priceMin, setPriceMin] = useState(state.price ? String(state.price[0]) : '');
  const [priceMax, setPriceMax] = useState(state.price ? String(state.price[1]) : '');
  const brands = facets?.brands ?? [];
  const visibleBrands = showAllBrands ? brands : brands.slice(0, 5);
  const applyPrice = () => {
    const a = Number(priceMin || 0);
    const b = Number(priceMax || 0);
    if (!priceMin && !priceMax) update({ preco: null });
    else if (Number.isInteger(a) && Number.isInteger(b) && b >= a) update({ preco: `${a}-${b}` });
  };
  const legend = { fontWeight: 600, fontSize: 14, mb: 1, p: 0 } as const;
  return (
    <Box component="form" aria-label="Filtros" onSubmit={(e) => e.preventDefault()} sx={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
      {mode === 'category' && subcategories?.length ? (
        <Box component="fieldset" sx={{ border: 0, p: 0, m: 0 }}>
          <Box component="legend" sx={legend}>Subcategoria</Box>
          {subcategories.map((c) => (
            <FormControlLabel key={c.slug} control={<Checkbox checked={state.sub.includes(c.slug)} onChange={() => update({ sub: toggle(state.sub, c.slug) })} />} label={c.name} sx={{ display: 'flex' }} />
          ))}
        </Box>
      ) : null}
      {mode === 'search' && facets?.categories.length ? (
        <Box component="fieldset" sx={{ border: 0, p: 0, m: 0 }}>
          <Box component="legend" sx={legend}>Categoria</Box>
          {facets.categories.map((c) => (
            <FormControlLabel key={c.slug} control={<Checkbox checked={state.categories.includes(c.slug)} onChange={() => update({ categoria: toggle(state.categories, c.slug) })} />} label={`${c.name} (${c.count})`} sx={{ display: 'flex' }} />
          ))}
        </Box>
      ) : null}
      {brands.length ? (
        <Box component="fieldset" sx={{ border: 0, p: 0, m: 0 }}>
          <Box component="legend" sx={legend}>Marca</Box>
          {visibleBrands.map((b) => (
            <FormControlLabel key={b.slug} control={<Checkbox checked={state.brands.includes(b.slug)} onChange={() => update({ marca: toggle(state.brands, b.slug) })} />} label={`${b.name} (${b.count})`} sx={{ display: 'flex' }} />
          ))}
          {brands.length > 5 ? (
            <Button variant="text" size="small" onClick={() => setShowAllBrands((v) => !v)}>
              {showAllBrands ? 'ver menos' : '+ ver mais'}
            </Button>
          ) : null}
        </Box>
      ) : null}
      <Box component="fieldset" sx={{ border: 0, p: 0, m: 0 }}>
        <Box component="legend" sx={legend}>Preço por unidade de venda (R$)</Box>
        <Stack direction="row" spacing={2}>
          <TextField size="small" label="Mín." value={priceMin} onChange={(e) => setPriceMin(e.target.value.replace(/\D/g, ''))} onBlur={applyPrice} slotProps={{ htmlInput: { inputMode: 'numeric' } }} />
          <TextField size="small" label="Máx." value={priceMax} onChange={(e) => setPriceMax(e.target.value.replace(/\D/g, ''))} onBlur={applyPrice} slotProps={{ htmlInput: { inputMode: 'numeric' } }} />
        </Stack>
      </Box>
      {facets?.sale_units.length ? (
        <Box component="fieldset" sx={{ border: 0, p: 0, m: 0 }}>
          <Box component="legend" sx={legend}>Unidade de venda</Box>
          <RadioGroup value={state.unit ?? ''} onChange={(e) => update({ unidade: e.target.value || null })}>
            <FormControlLabel value="" control={<Radio />} label="Todas" />
            {facets.sale_units.map((u) => (
              <FormControlLabel key={u.value} value={u.value} control={<Radio />} label={`${u.label || SALE_UNIT_LABEL[u.value as SaleUnit]} (${u.count})`} />
            ))}
          </RadioGroup>
        </Box>
      ) : null}
      <FormControlLabel control={<Checkbox checked={state.inStock} onChange={(e) => update({ estoque: e.target.checked ? '1' : null })} />} label="Somente em estoque" />
    </Box>
  );
}

/** Listagem comum a categoria e busca (UX §4.2/§4.3). */
export function ProductListing({ mode, data, isLoading, isFetching, error, onRetry, subcategories, emptyContent }: Props) {
  const { state, update, clear } = useListingParams(mode);
  const [drawer, setDrawer] = useState(false);
  const filterButton = useRef<HTMLButtonElement>(null);
  const { quickAdd, pendingId } = useQuickAdd();
  const total = data?.meta.total ?? 0;

  const chips: { key: string; label: string; remove: () => void }[] = [];
  state.brands.forEach((b) => {
    const name = data?.facets.brands.find((x) => x.slug === b)?.name ?? b;
    chips.push({ key: `b-${b}`, label: name, remove: () => update({ marca: toggle(state.brands, b) }) });
  });
  state.sub.forEach((s) => chips.push({ key: `s-${s}`, label: subcategories?.find((c) => c.slug === s)?.name ?? s, remove: () => update({ sub: toggle(state.sub, s) }) }));
  state.categories.forEach((s) => chips.push({ key: `c-${s}`, label: data?.facets.categories.find((c) => c.slug === s)?.name ?? s, remove: () => update({ categoria: toggle(state.categories, s) }) }));
  if (state.price) chips.push({ key: 'price', label: `R$ ${state.price[0]}–${state.price[1]}`, remove: () => update({ preco: null }) });
  if (state.unit) chips.push({ key: 'unit', label: SALE_UNIT_LABEL[state.unit], remove: () => update({ unidade: null }) });
  if (state.inStock) chips.push({ key: 'stock', label: 'Somente em estoque', remove: () => update({ estoque: null }) });

  useEffect(() => {
    if (!drawer) filterButton.current?.focus({ preventScroll: true });
  }, [drawer]);

  const panel = <FilterPanel mode={mode} state={state} update={update} facets={data?.facets} subcategories={subcategories} />;

  return (
    <Box sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr', md: '260px 1fr' }, gap: 6 }}>
      <Box component="aside" sx={{ display: { xs: 'none', md: 'block' } }}>
        <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', mb: 3 }}>
          <Typography variant="overline" component="h2">Filtros</Typography>
          {chips.length ? <Button variant="text" size="small" onClick={clear}>Limpar</Button> : null}
        </Box>
        {panel}
      </Box>
      <Box sx={{ minWidth: 0 }}>
        <Box sx={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 2, mb: 3 }}>
          <Button
            ref={filterButton}
            variant="outlined"
            startIcon={<TuneOutlined />}
            onClick={() => setDrawer(true)}
            sx={{ display: { md: 'none' } }}
          >
            Filtros{chips.length ? ` (${chips.length})` : ''}
          </Button>
          <FormControl size="small" sx={{ minWidth: 180 }}>
            <InputLabel id="sort-label">Ordenar</InputLabel>
            <Select labelId="sort-label" label="Ordenar" value={state.sort} onChange={(e) => update({ ordem: e.target.value })}>
              {SORT_OPTIONS.filter((s) => mode === 'search' || s.value !== 'relevancia').map((s) => (
                <MenuItem key={s.value} value={s.value}>
                  {s.label}
                </MenuItem>
              ))}
            </Select>
          </FormControl>
          <ToggleButtonGroup exclusive size="small" value={state.view} onChange={(_, v: 'grid' | 'list' | null) => v && update({ vista: v === 'list' ? 'lista' : 'grade' }, true)} aria-label="Modo de exibição">
            <ToggleButton value="grid" aria-label="Grade"><GridViewOutlined /></ToggleButton>
            <ToggleButton value="list" aria-label="Lista"><ViewListOutlined /></ToggleButton>
          </ToggleButtonGroup>
          <Typography aria-live="polite" variant="body2" color="text.secondary" sx={{ ml: 'auto' }}>
            {data ? `${total} ${total === 1 ? 'produto encontrado' : 'produtos encontrados'}` : ''}
          </Typography>
        </Box>
        {chips.length ? (
          <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 2, mb: 3, alignItems: 'center' }}>
            {chips.map((c) => (
              <Chip key={c.key} label={c.label} onDelete={c.remove} aria-label={`Remover filtro ${c.label}`} />
            ))}
            <Button variant="text" size="small" onClick={clear}>Limpar</Button>
          </Box>
        ) : null}
        {isFetching && !isLoading ? <LinearProgress aria-label="Carregando resultados" sx={{ mb: 2 }} /> : null}
        {error && !data ? (
          <ErrorState title="Não foi possível carregar os produtos." error={error} onRetry={onRetry} />
        ) : isLoading ? (
          <Box aria-busy="true" sx={{ display: 'grid', gridTemplateColumns: { xs: 'repeat(2, 1fr)', sm: 'repeat(3, 1fr)', lg: 'repeat(4, 1fr)' }, gap: 3 }}>
            <span className="visually-hidden">Carregando produtos</span>
            {Array.from({ length: 8 }, (_, i) => <ProductCardSkeleton key={i} />)}
          </Box>
        ) : data && data.data.length === 0 ? (
          chips.length ? (
            <EmptyState
              icon={<SearchOutlined />}
              title="Nenhum produto com esses filtros."
              action={
                <Stack direction="row" spacing={2}>
                  <Button onClick={chips[chips.length - 1].remove} variant="outlined">Remover “{chips[chips.length - 1].label}”</Button>
                  <Button onClick={clear}>Limpar filtros</Button>
                </Stack>
              }
            />
          ) : (
            emptyContent
          )
        ) : data ? (
          <>
            <Box
              component="ul"
              sx={{
                listStyle: 'none', p: 0, m: 0, opacity: isFetching ? 0.6 : 1, display: 'grid', gap: 3,
                gridTemplateColumns: state.view === 'list' ? '1fr' : { xs: 'repeat(2, 1fr)', sm: 'repeat(3, 1fr)', lg: 'repeat(4, 1fr)' },
              }}
            >
              {data.data.map((p) => (
                <li key={p.id}>
                  <ProductCardView product={p} layout={state.view} onQuickAdd={quickAdd} quickAddPending={pendingId === p.id} />
                </li>
              ))}
            </Box>
            {data.meta.last_page > 1 ? (
              <Pagination
                sx={{ mt: 6, display: 'flex', justifyContent: 'center' }}
                count={data.meta.last_page}
                page={data.meta.current_page}
                onChange={(_, page) => {
                  update({ pagina: page > 1 ? String(page) : null });
                  window.scrollTo({ top: 0 });
                }}
                getItemAriaLabel={(type, page) => (type === 'page' ? `Página ${page}` : type === 'next' ? 'Próxima página' : type === 'previous' ? 'Página anterior' : String(type))}
              />
            ) : null}
          </>
        ) : null}
      </Box>
      <Drawer anchor="bottom" open={drawer} onClose={() => setDrawer(false)} slotProps={{ paper: { sx: { maxHeight: '90vh', p: 4, borderTopLeftRadius: 16, borderTopRightRadius: 16 } } }}>
        <Typography variant="h3" component="h2" sx={{ mb: 3 }}>Filtros</Typography>
        {panel}
        <Stack direction="row" spacing={2} sx={{ mt: 4, position: 'sticky', bottom: 0, bgcolor: 'background.paper', py: 2 }}>
          <Button variant="outlined" onClick={clear}>Limpar</Button>
          <Button onClick={() => setDrawer(false)} fullWidth>Ver {total} produtos</Button>
        </Stack>
      </Drawer>
    </Box>
  );
}
