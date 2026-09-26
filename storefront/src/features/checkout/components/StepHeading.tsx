import Typography from '@mui/material/Typography';
import { useEffect, useRef, type ReactNode } from 'react';

/** Ao mudar de passo, foco vai para o <h2> (UX §4.6 A11y). */
export function StepHeading({ children }: { children: ReactNode }) {
  const ref = useRef<HTMLHeadingElement>(null);
  useEffect(() => ref.current?.focus({ preventScroll: false }), []);
  return (
    <Typography variant="h3" component="h2" tabIndex={-1} ref={ref} sx={{ outline: 'none', mb: 4 }}>
      {children}
    </Typography>
  );
}
