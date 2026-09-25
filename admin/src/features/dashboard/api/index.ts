import { useQuery } from '@tanstack/react-query';
import { getData } from '@/shared/api/client';
import type { Dashboard } from '@/shared/api/types';

export const dashboardKey = ['admin', 'dashboard'] as const;

export function useDashboard() {
  return useQuery({ queryKey: dashboardKey, queryFn: () => getData<Dashboard>('/admin/dashboard'), refetchInterval: 60_000 });
}
