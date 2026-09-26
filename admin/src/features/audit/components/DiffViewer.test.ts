import { describe, expect, it } from 'vitest';
import { diffRows } from './DiffViewer';

describe('diffRows', () => {
  it('marca campos alterados', () => {
    const rows = diffRows({ name: 'A', is_featured: false, cpf: '••••' }, { name: 'B', is_featured: false, cpf: '••••' });
    expect(rows.find((r) => r.field === 'name')?.changed).toBe(true);
    expect(rows.find((r) => r.field === 'is_featured')?.changed).toBe(false);
  });
});
