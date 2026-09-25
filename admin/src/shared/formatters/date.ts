import type { ISODate, ISODateTime } from '@/shared/api/types';

export const TZ = 'America/Sao_Paulo';

const dateFmt = new Intl.DateTimeFormat('pt-BR', { timeZone: TZ, day: '2-digit', month: '2-digit', year: 'numeric' });
const dateTimeFmt = new Intl.DateTimeFormat('pt-BR', {
  timeZone: TZ,
  day: '2-digit',
  month: '2-digit',
  year: 'numeric',
  hour: '2-digit',
  minute: '2-digit',
});
const timeFmt = new Intl.DateTimeFormat('pt-BR', { timeZone: TZ, hour: '2-digit', minute: '2-digit' });

export function formatDateTime(iso: ISODateTime | null | undefined): string {
  if (!iso) return '—';
  return dateTimeFmt.format(new Date(iso)).replace(',', '');
}

export function formatTime(iso: ISODateTime | null | undefined): string {
  if (!iso) return '—';
  return timeFmt.format(new Date(iso));
}

/** Data ISO (YYYY-MM-DD) ou data-hora → "24/09/2026". */
export function formatDate(value: ISODate | ISODateTime | null | undefined): string {
  if (!value) return '—';
  if (/^\d{4}-\d{2}-\d{2}$/.test(value)) {
    const [y, m, d] = value.split('-');
    return `${d}/${m}/${y}`;
  }
  return dateFmt.format(new Date(value));
}

/** "há 5 min", "há 2 h", "há 3 dias". */
export function formatRelative(iso: ISODateTime | null | undefined, now: Date = new Date()): string {
  if (!iso) return '—';
  const diff = Math.round((now.getTime() - new Date(iso).getTime()) / 1000);
  const rtf = new Intl.RelativeTimeFormat('pt-BR', { numeric: 'auto' });
  const abs = Math.abs(diff);
  if (abs < 60) return 'agora';
  if (abs < 3600) return rtf.format(-Math.round(diff / 60), 'minute');
  if (abs < 86400) return rtf.format(-Math.round(diff / 3600), 'hour');
  return rtf.format(-Math.round(diff / 86400), 'day');
}

/** Data de hoje (YYYY-MM-DD) em America/Sao_Paulo. */
export function todayISO(now: Date = new Date()): ISODate {
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit' }).format(now);
  return parts;
}

export function addDaysISO(date: ISODate, days: number): ISODate {
  const [y, m, d] = date.split('-').map(Number);
  const dt = new Date(Date.UTC(y, m - 1, d + days));
  return dt.toISOString().slice(0, 10);
}

export function startOfMonthISO(date: ISODate): ISODate {
  return `${date.slice(0, 7)}-01`;
}

export function endOfPrevMonthISO(date: ISODate): ISODate {
  return addDaysISO(startOfMonthISO(date), -1);
}

/** Valor para <input type="datetime-local"> (hora de SP) a partir de ISO UTC. */
export function isoToLocalInput(iso: ISODateTime | null | undefined): string {
  if (!iso) return '';
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: TZ,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  }).formatToParts(new Date(iso));
  const get = (t: string) => parts.find((p) => p.type === t)?.value ?? '';
  return `${get('year')}-${get('month')}-${get('day')}T${get('hour')}:${get('minute')}`;
}

/** "2026-09-24T10:00" (hora de SP, UTC−3 fixo desde 2019) → ISO UTC com Z. */
export function localInputToIso(value: string): ISODateTime | null {
  if (!value) return null;
  const d = new Date(`${value}:00-03:00`);
  if (Number.isNaN(d.getTime())) return null;
  return d.toISOString().replace('.000Z', 'Z');
}
