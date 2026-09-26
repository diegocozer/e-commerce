import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, getData, type Envelope } from '@/shared/api/client';
import type { Setting, SettingKey } from '@/shared/api/types';

export const settingsKey = ['admin', 'settings'] as const;

export function useSettings() {
  return useQuery({ queryKey: settingsKey, queryFn: () => getData<Setting[]>('/admin/settings') });
}

export function useSaveSettings() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (values: Partial<Record<SettingKey, unknown>>) => api.patch<Envelope<Setting[]>>('/admin/settings', { values }).then((r) => r.data),
    onSuccess: (data) => qc.setQueryData(settingsKey, data),
  });
}
