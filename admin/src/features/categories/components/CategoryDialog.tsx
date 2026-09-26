import { zodResolver } from '@hookform/resolvers/zod';
import { useForm } from 'react-hook-form';
import type { AdminCategory } from '@/shared/api/types';
import { useFormSubmit } from '@/shared/hooks/useFormSubmit';
import { notify } from '@/shared/ui';
import { FormDialog, RHFSelect, RHFSwitch, RHFTextField } from '@/shared/ui/form';
import { useSaveCategory } from '../api';
import { categorySchema, type CategoryForm } from '../schemas';

interface Props {
  category: AdminCategory | null;
  parentId: number | null;
  parents: { id: number; label: string; depth: number }[];
  onClose: () => void;
}

export function CategoryDialog({ category, parentId, parents, onClose }: Props) {
  const save = useSaveCategory(category?.id ?? null);
  const { control, handleSubmit, setError } = useForm<CategoryForm>({
    resolver: zodResolver(categorySchema),
    defaultValues: {
      name: category?.name ?? '', slug: category?.slug ?? '', parent_id: category?.parent_id ?? parentId, description_html: category?.description_html ?? '',
      meta_title: category?.meta_title ?? '', meta_description: category?.meta_description ?? '', is_active: category?.is_active ?? true,
    },
  });
  const { error, run } = useFormSubmit(setError);
  const onSubmit = handleSubmit(async (v) => {
    const ok = await run(() => save.mutateAsync({ ...v, slug: v.slug || undefined, description_html: v.description_html || null, meta_title: v.meta_title || null, meta_description: v.meta_description || null }));
    if (ok) {
      notify.success('Categoria salva');
      onClose();
    }
  });
  // Profundidade máxima 3: só pais de nível 1–2 e nunca a própria categoria.
  const options = parents.filter((p) => p.depth < 3 && p.id !== category?.id).map((p) => ({ value: p.id, label: p.label }));
  return (
    <FormDialog open title={category ? `Editar categoria: ${category.name}` : 'Nova categoria'} onClose={onClose} onSubmit={(e) => void onSubmit(e)} submitting={save.isPending} error={error}>
      <RHFTextField control={control} name="name" label="Nome" required autoFocus />
      <RHFTextField control={control} name="slug" label="Slug" helperText="Vazio = gerado do nome" />
      <RHFSelect control={control} name="parent_id" label="Categoria pai" emptyLabel="Nenhuma (nível 1)" options={options} />
      <RHFTextField control={control} name="description_html" label="Descrição" multiline minRows={3} />
      <RHFTextField control={control} name="meta_title" label="Meta title" maxLength={120} />
      <RHFTextField control={control} name="meta_description" label="Meta description" multiline minRows={2} maxLength={320} />
      <RHFSwitch control={control} name="is_active" label="Ativa" />
    </FormDialog>
  );
}
