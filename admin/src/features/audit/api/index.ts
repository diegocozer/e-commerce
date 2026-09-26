import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api, type QueryParams } from '@/shared/api/client';
import type { FailedJob } from '@/shared/api/extraTypes';
import type { AuditLog, Paginated } from '@/shared/api/types';

export function useAuditLogs(params: QueryParams) {
  return useQuery({ queryKey: ['admin', 'audit-logs', 'list', params], queryFn: () => api.get<Paginated<AuditLog>>('/admin/audit-logs', params), placeholderData: keepPreviousData });
}
export function useFailedJobs(params: QueryParams, enabled: boolean) {
  return useQuery({ queryKey: ['admin', 'failed-jobs', params], queryFn: () => api.get<Paginated<FailedJob>>('/admin/failed-jobs', params), enabled });
}
