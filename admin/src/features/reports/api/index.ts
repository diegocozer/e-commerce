import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { downloadFile, getData, type QueryParams } from '@/shared/api/client';
import type { ReportName, ReportResponse } from '@/shared/api/types';

export type AnyRow = Record<string, string | number | boolean | null>;

export function useReport(name: ReportName, params: QueryParams, enabled: boolean) {
  return useQuery({
    queryKey: ['admin', 'reports', name, params],
    queryFn: () => getData<ReportResponse<AnyRow, Record<string, number | null>>>(`/admin/reports/${name}`, params),
    enabled,
    placeholderData: keepPreviousData,
    staleTime: 60_000,
  });
}

/** CSV síncrono (API §3.G.15) — download direto, sem cache. */
export const exportReportCsv = (name: ReportName, params: QueryParams) =>
  downloadFile(`/admin/reports/${name}`, { ...params, format: 'csv' }, `${name}_${String(params.date_from ?? '')}_${String(params.date_to ?? '')}.csv`);
