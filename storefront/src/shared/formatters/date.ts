// Datas da API em UTC (ISO-8601 com Z); exibição em America/Sao_Paulo (UX §6.1).
const TZ = 'America/Sao_Paulo';
const dateFmt = new Intl.DateTimeFormat('pt-BR', { timeZone: TZ, day: '2-digit', month: '2-digit', year: 'numeric' });
const timeFmt = new Intl.DateTimeFormat('pt-BR', { timeZone: TZ, hour: '2-digit', minute: '2-digit', hour12: false });

/** "2026-09-24T13:00:00Z" → "24/09/2026". Aceita também "YYYY-MM-DD" (data pura). */
export function formatDate(iso: string | null | undefined): string {
  if (!iso) return '';
  if (/^\d{4}-\d{2}-\d{2}$/.test(iso)) {
    const [y, m, d] = iso.split('-');
    return `${d}/${m}/${y}`;
  }
  const date = new Date(iso);
  return Number.isNaN(date.getTime()) ? '' : dateFmt.format(date);
}

/** "2026-09-24T13:00:00Z" → "24/09/2026 10:00". */
export function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return '';
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return '';
  return `${dateFmt.format(date)} ${timeFmt.format(date)}`;
}

/** mm:ss para contagem regressiva. */
export function formatCountdown(ms: number): string {
  const total = Math.max(0, Math.floor(ms / 1000));
  const m = Math.floor(total / 60);
  const s = total % 60;
  return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
}
