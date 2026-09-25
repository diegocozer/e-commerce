// Offset entre o relógio do servidor (header Date) e o do cliente — usado na contagem
// regressiva do PIX (UX §4.7: não confiar só no relógio do cliente).
let offsetMs = 0;

export function updateServerClock(dateHeader: string | null | undefined): void {
  if (!dateHeader) return;
  const server = Date.parse(dateHeader);
  if (Number.isNaN(server)) return;
  offsetMs = server - Date.now();
}

export function serverNow(): number {
  return Date.now() + offsetMs;
}
