import { zodResolver } from '@hookform/resolvers/zod';
import { Alert, Button } from '@mui/material';
import { useEffect, useMemo, useState } from 'react';
import { useForm } from 'react-hook-form';
import { useNavigate, useParams } from 'react-router-dom';
import { useBreadcrumb } from '@/app/breadcrumb';
import { errorMessage, isApiError } from '@/shared/api/errors';
import type { AdminProduct } from '@/shared/api/types';
import { useCan } from '@/shared/auth';
import { ErrorState, LoadingBlock, notify } from '@/shared/ui';
import { applyServerErrors, flattenErrors, FormErrorSummary, FormPage } from '@/shared/ui/form';
import { useProduct, useSaveProduct } from '../api';
import { GeneralSection } from '../components/GeneralSection';
import { ImagesSection } from '../components/ImagesSection';
import { PriceTiersSection } from '../components/PriceTiersSection';
import { SaleUnitSection } from '../components/SaleUnitSection';
import { SeoSection } from '../components/SeoSection';
import { VariantsSection } from '../components/VariantsSection';
import { emptyProduct, formToPayload, productSchema, productToForm, type ProductForm } from '../schemas/product';

const SECTION_OF: Record<string, string> = {
  name: 'geral', slug: 'geral', short_description: 'geral', description_html: 'geral', brand_id: 'geral', primary_category_id: 'geral', category_ids: 'geral', specifications: 'geral', is_active: 'geral',
  sale_unit: 'unidade', min_quantity: 'unidade', max_quantity: 'unidade', quantity_step: 'unidade', fixed_width_m: 'unidade', min_width_m: 'unidade', max_width_m: 'unidade', min_height_m: 'unidade', max_height_m: 'unidade', min_billable_area_m2: 'unidade',
  variants: 'variantes', meta_title: 'seo', meta_description: 'seo',
};

export function ProductEditor({ product }: { product: AdminProduct | null }) {
  const navigate = useNavigate();
  const canManage = useCan('products.manage');
  const canPrices = useCan('prices.manage');
  const canMoveStock = useCan('inventory.move');
  const readOnly = !canManage;
  const id = product?.id ?? null;
  const save = useSaveProduct(id);
  const [slugTouched, setSlugTouched] = useState(!!product);
  const [extraErrors, setExtraErrors] = useState<string[]>([]);
  const [stale, setStale] = useState(false);
  const defaults = useMemo(() => (product ? productToForm(product) : emptyProduct()), [product]);
  const form = useForm<ProductForm>({ resolver: zodResolver(productSchema), defaultValues: defaults, mode: 'onBlur', reValidateMode: 'onChange' });
  const { control, handleSubmit, reset, setError, setValue, getValues, formState } = form;
  useBreadcrumb(product ? product.name : 'Novo produto');

  useEffect(() => {
    reset(defaults);
  }, [defaults, reset]);

  const onSubmit = handleSubmit(
    async (values) => {
      setExtraErrors([]);
      const body = formToPayload(values, { canPrices, canMoveStock });
      if (product) body.expected_updated_at = product.updated_at;
      try {
        const res = await save.mutateAsync(body);
        notify.success('Produto salvo');
        if (!product) navigate(`/produtos/${res.data.id}`, { replace: true });
        else reset(productToForm(res.data));
      } catch (e) {
        if (isApiError(e) && e.code === 'stale_resource') return setStale(true);
        const rest = applyServerErrors(e, setError);
        if (!isApiError(e) || !e.fieldErrors) notify.error(errorMessage(e));
        setExtraErrors(rest);
      }
    },
    () => setExtraErrors([]),
  );

  const errorPaths = flattenErrors(formState.errors).map((e) => SECTION_OF[e.path.split('.')[0]]);
  const sections = [
    { id: 'geral', label: 'Geral' },
    { id: 'unidade', label: 'Unidade de venda' },
    { id: 'variantes', label: 'Variantes' },
    ...(product ? [{ id: 'faixas', label: 'Faixas de preço' }, { id: 'imagens', label: 'Imagens' }] : []),
    { id: 'seo', label: 'SEO' },
  ].map((s) => ({ ...s, hasError: errorPaths.includes(s.id) }));

  return (
    <FormPage
      title={product ? `Editar produto: ${product.name}` : 'Novo produto'}
      back={{ to: '/produtos', label: 'Produtos' }}
      sections={sections}
      isDirty={formState.isDirty}
      isSubmitting={formState.isSubmitting}
      onSubmit={(e) => void onSubmit(e)}
      onDiscard={() => reset(defaults)}
      saveLabel="Salvar produto"
      readOnly={readOnly}
    >
      {readOnly && <Alert severity="info">Somente leitura: você não tem permissão para editar produtos.</Alert>}
      {stale && (
        <Alert severity="warning" action={<Button color="inherit" onClick={() => window.location.reload()}>Recarregar</Button>}>
          Este registro foi alterado por outra pessoa. Recarregue (suas alterações serão perdidas).
        </Alert>
      )}
      <FormErrorSummary errors={formState.errors} extra={extraErrors} />
      <GeneralSection
        control={control}
        setValue={setValue}
        setError={setError}
        productId={id}
        slugTouched={slugTouched}
        onSlugTouched={() => setSlugTouched(true)}
        urlPath={product?.url_path ?? '/categoria/produto'}
        activationIssues={product?.activation_issues ?? []}
        readOnly={readOnly}
      />
      <SaleUnitSection control={control} locked={!!product?.sale_unit_locked} originalUnit={product?.sale_unit ?? null} readOnly={readOnly} />
      <VariantsSection control={control} setError={setError} setValue={setValue} getValues={getValues} productId={id} existing={product?.variants ?? []} canPrices={canPrices} canMoveStock={canMoveStock} readOnly={readOnly} />
      {product && <PriceTiersSection variants={product.variants} saleUnit={product.sale_unit} />}
      {product && <ImagesSection product={product} readOnly={readOnly} />}
      <SeoSection control={control} urlPath={product?.url_path ?? '/categoria/produto'} readOnly={readOnly} />
    </FormPage>
  );
}

export default function ProductFormPage() {
  const params = useParams();
  const id = params.id ? Number(params.id) : null;
  const q = useProduct(id);
  if (id === null) return <ProductEditor product={null} />;
  if (q.isPending) return <LoadingBlock lines={10} label="Carregando produto" />;
  if (q.error || !q.data) return <ErrorState error={q.error} onRetry={() => void q.refetch()} />;
  return <ProductEditor product={q.data} />;
}
