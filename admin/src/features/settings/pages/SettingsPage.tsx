import { Button, Stack, Typography } from '@mui/material';
import { useEffect, useState } from 'react';
import { errorMessage, isApiError } from '@/shared/api/errors';
import type { Setting, SettingKey } from '@/shared/api/types';
import { formatDateTime } from '@/shared/formatters/date';
import { useUnsavedChangesGuard } from '@/shared/hooks/useUnsavedChangesGuard';
import { ErrorState, LoadingBlock, notify, PageHeader } from '@/shared/ui';
import { FormSection, UnsavedChangesDialog } from '@/shared/ui/form';
import { useSaveSettings, useSettings } from '../api';
import { SettingField } from '../components/SettingField';
import { GROUP_LABEL, validateSetting } from '../schemas';

/** Uma seção por grupo; cada seção salva independente (UX §5.13). */
function GroupSection({ group, settings, onDirty }: { group: string; settings: Setting[]; onDirty: (group: string, dirty: boolean) => void }) {
  const save = useSaveSettings();
  const initial = () => Object.fromEntries(settings.map((s) => [s.key, s.value])) as Record<SettingKey, unknown>;
  const [draft, setDraft] = useState(initial);
  const [errors, setErrors] = useState<Partial<Record<SettingKey, string>>>({});
  const changed = settings.filter((s) => JSON.stringify(draft[s.key]) !== JSON.stringify(s.value));
  useEffect(() => onDirty(group, changed.length > 0), [changed.length, group, onDirty]);

  const onSave = () => {
    const errs: Partial<Record<SettingKey, string>> = {};
    for (const s of changed) {
      const m = validateSetting(s.key, draft[s.key]);
      if (m) errs[s.key] = m;
    }
    setErrors(errs);
    if (Object.keys(errs).length) return;
    const values = Object.fromEntries(changed.map((s) => [s.key, draft[s.key]]));
    save.mutate(values, {
      onSuccess: () => notify.success(`${GROUP_LABEL[group] ?? group}: configurações salvas`),
      onError: (e) => {
        if (isApiError(e) && e.fieldErrors) {
          setErrors(Object.fromEntries(Object.entries(e.fieldErrors).map(([k, m]) => [k.replace(/^values\./, ''), m[0]])) as Partial<Record<SettingKey, string>>);
        } else notify.error(errorMessage(e));
      },
    });
  };
  const last = settings.map((s) => s.updated_at).filter(Boolean).sort().pop();
  return (
    <FormSection
      id={`grupo-${group}`}
      title={GROUP_LABEL[group] ?? group}
      description={last ? `Última alteração ${formatDateTime(last)}` : undefined}
      actions={
        <Stack direction="row" spacing={1}>
          <Button disabled={!changed.length} onClick={() => { setDraft(initial()); setErrors({}); }}>Descartar</Button>
          <Button variant="contained" disabled={!changed.length || save.isPending} onClick={onSave}>Salvar</Button>
        </Stack>
      }
    >
      <Stack spacing={3}>
        {settings.map((s) => (
          <SettingField key={s.key} setting={s} value={draft[s.key]} error={errors[s.key] ?? null} onChange={(v) => setDraft((d) => ({ ...d, [s.key]: v }))} />
        ))}
      </Stack>
    </FormSection>
  );
}

export default function SettingsPage() {
  const q = useSettings();
  const [dirtyGroups, setDirtyGroups] = useState<Record<string, boolean>>({});
  const onDirty = useStableDirty(setDirtyGroups);
  const blocker = useUnsavedChangesGuard(Object.values(dirtyGroups).some(Boolean));
  if (q.isPending) return <LoadingBlock lines={10} />;
  if (q.error || !q.data) return <ErrorState error={q.error} onRetry={() => void q.refetch()} />;
  const groups = Array.from(new Set(q.data.map((s) => s.group)));
  return (
    <>
      <PageHeader title="Configurações" subtitle="Cada seção é salva separadamente. O driver de pagamento e a chave PIX são definidos no ambiente do servidor." />
      <Stack spacing={4}>
        {groups.map((g) => (
          <GroupSection key={`${g}-${q.dataUpdatedAt}`} group={g} settings={q.data.filter((s) => s.group === g)} onDirty={onDirty} />
        ))}
      </Stack>
      <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 4 }}>Alterações são registradas na auditoria.</Typography>
      <UnsavedChangesDialog blocker={blocker} />
    </>
  );
}

function useStableDirty(set: React.Dispatch<React.SetStateAction<Record<string, boolean>>>) {
  const [fn] = useState(() => (group: string, dirty: boolean) => set((d) => (d[group] === dirty ? d : { ...d, [group]: dirty })));
  return fn;
}
