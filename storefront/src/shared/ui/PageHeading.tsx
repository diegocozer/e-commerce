import Typography, { type TypographyProps } from '@mui/material/Typography';
import { useEffect, useRef, type ReactNode } from 'react';

/** h1 da página; recebe foco na montagem (UX §6.10: foco no h1 ao trocar de rota). */
export function PageHeading({ children, focus = true, ...rest }: { children: ReactNode; focus?: boolean } & TypographyProps) {
  const ref = useRef<HTMLHeadingElement>(null);
  useEffect(() => {
    if (focus) ref.current?.focus({ preventScroll: true });
  }, [focus]);
  return (
    <Typography variant="h1" component="h1" tabIndex={-1} ref={ref} sx={{ outline: 'none', mb: 4, ...rest.sx }} {...rest}>
      {children}
    </Typography>
  );
}
