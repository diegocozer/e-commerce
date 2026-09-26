import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { describe, expect, it } from 'vitest';
import { MoneyField } from '../MoneyField';

function Harness({ onValue }: { onValue: (v: number | null) => void }) {
  const [v, setV] = useState<number | null>(null);
  return (
    <MoneyField
      label="Preço"
      value={v}
      onChange={(c) => {
        setV(c);
        onValue(c);
      }}
    />
  );
}

describe('MoneyField', () => {
  it('emite centavos inteiros e formata no blur', async () => {
    const values: (number | null)[] = [];
    render(<Harness onValue={(v) => values.push(v)} />);
    const input = screen.getByLabelText('Preço');
    await userEvent.type(input, '1234,5');
    expect(values.at(-1)).toBe(123450);
    await userEvent.tab();
    expect(input).toHaveValue('1.234,50');
  });

  it('mostra erro para formato inválido', async () => {
    render(<Harness onValue={() => {}} />);
    await userEvent.type(screen.getByLabelText('Preço'), '1,234');
    expect(screen.getByText(/Valor inválido/)).toBeInTheDocument();
  });
});
