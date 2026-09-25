import { createContext, useCallback, useContext, useMemo, useState, type ReactNode } from 'react';

interface MiniCartState {
  open: boolean;
  highlightId: number | null;
  show: (highlightId?: number | null) => void;
  close: () => void;
}

const Ctx = createContext<MiniCartState>({ open: false, highlightId: null, show: () => undefined, close: () => undefined });

export function MiniCartProvider({ children }: { children: ReactNode }) {
  const [open, setOpen] = useState(false);
  const [highlightId, setHighlight] = useState<number | null>(null);
  const show = useCallback((id?: number | null) => {
    setHighlight(id ?? null);
    setOpen(true);
  }, []);
  const close = useCallback(() => setOpen(false), []);
  const value = useMemo(() => ({ open, highlightId, show, close }), [open, highlightId, show, close]);
  return <Ctx.Provider value={value}>{children}</Ctx.Provider>;
}

export function useMiniCart() {
  return useContext(Ctx);
}
