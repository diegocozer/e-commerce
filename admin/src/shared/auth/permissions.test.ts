import { describe, expect, it } from 'vitest';
import { makeMe } from '@/mocks/seed';
import { hasPermission } from './me';

describe('hasPermission', () => {
  const me = makeMe({ is_super_admin: false, permissions: ['orders.view', 'orders.pickup'] });
  it('checa permissão única e lista (A | B)', () => {
    expect(hasPermission(me, 'orders.view')).toBe(true);
    expect(hasPermission(me, 'orders.fulfill')).toBe(false);
    expect(hasPermission(me, ['orders.fulfill', 'orders.pickup'])).toBe(true);
    expect(hasPermission(null, 'orders.view')).toBe(false);
  });
  it('super-admin passa em tudo', () => {
    expect(hasPermission(makeMe({ is_super_admin: true, permissions: [] }), 'admin_users.manage')).toBe(true);
  });
});
