import { useSyncExternalStore } from 'react';

export type NotifySeverity = 'success' | 'error' | 'info' | 'warning';
export interface Notification {
  id: number;
  message: string;
  severity: NotifySeverity;
  action?: { label: string; onClick: () => void };
}

let queue: Notification[] = [];
let seq = 0;
const listeners = new Set<() => void>();
const emit = () => listeners.forEach((l) => l());

/** Fila de snackbars utilizável fora do React (UX §6.3: um por vez). */
export function notify(message: string, severity: NotifySeverity = 'success', action?: Notification['action']): void {
  queue = [...queue, { id: ++seq, message, severity, action }];
  emit();
}
notify.success = (m: string) => notify(m, 'success');
notify.error = (m: string, action?: Notification['action']) => notify(m, 'error', action);
notify.info = (m: string) => notify(m, 'info');
notify.warning = (m: string) => notify(m, 'warning');

export function dismissNotification(id: number): void {
  queue = queue.filter((n) => n.id !== id);
  emit();
}

function subscribe(l: () => void) {
  listeners.add(l);
  return () => listeners.delete(l);
}
const snapshot = () => queue;

export function useNotifications(): Notification[] {
  return useSyncExternalStore(subscribe, snapshot, snapshot);
}

export function resetNotifications(): void {
  queue = [];
  emit();
}
