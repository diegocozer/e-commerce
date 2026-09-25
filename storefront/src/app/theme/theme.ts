import { createTheme } from '@mui/material/styles';
import type { CSSProperties } from 'react';
import { colors, fonts, radius } from './tokens';

declare module '@mui/material/styles' {
  interface Palette {
    header: string;
    footer: string;
    border: { input: string };
  }
  interface PaletteOptions {
    header?: string;
    footer?: string;
    border?: { input: string };
  }
  interface TypographyVariants {
    price: CSSProperties;
    total: CSSProperties;
  }
  interface TypographyVariantsOptions {
    price?: CSSProperties & Record<string, unknown>;
    total?: CSSProperties & Record<string, unknown>;
  }
}

declare module '@mui/material/Typography' {
  interface TypographyPropsVariantOverrides {
    price: true;
    total: true;
  }
}

const md = '@media (min-width:900px)';
const tnum = { fontVariantNumeric: 'tabular-nums' } as const;

export const theme = createTheme({
  spacing: 4,
  shape: { borderRadius: radius.base },
  palette: {
    primary: colors.primary,
    secondary: colors.secondary,
    success: colors.success,
    warning: colors.warning,
    error: colors.error,
    info: colors.info,
    text: colors.text,
    divider: colors.divider,
    background: colors.background,
    header: colors.header,
    footer: colors.footer,
    border: { input: colors.borderInput },
  },
  typography: {
    fontFamily: fonts.body,
    h1: { fontFamily: fonts.heading, fontWeight: 800, fontSize: 26, lineHeight: 1.2, [md]: { fontSize: 34 } },
    h2: { fontFamily: fonts.heading, fontWeight: 700, fontSize: 22, lineHeight: 1.25, [md]: { fontSize: 28 } },
    h3: { fontFamily: fonts.heading, fontWeight: 700, fontSize: 18, lineHeight: 1.3, [md]: { fontSize: 22 } },
    h4: { fontFamily: fonts.body, fontWeight: 600, fontSize: 16, lineHeight: 1.35, [md]: { fontSize: 18 } },
    h5: { fontFamily: fonts.body, fontWeight: 600, fontSize: 16 },
    h6: { fontFamily: fonts.body, fontWeight: 600, fontSize: 15 },
    body1: { fontSize: 16, lineHeight: 1.5 },
    body2: { fontSize: 14, lineHeight: 1.45 },
    caption: { fontSize: 12, fontWeight: 500, lineHeight: 1.4 },
    overline: { fontSize: 11, fontWeight: 600, letterSpacing: '0.08em' },
    button: { fontSize: 15, fontWeight: 600, textTransform: 'none' },
    price: { fontFamily: fonts.body, fontWeight: 700, fontSize: 24, lineHeight: 1.1, ...tnum, [md]: { fontSize: 30 } },
    total: { fontFamily: fonts.body, fontWeight: 700, fontSize: 20, lineHeight: 1.2, ...tnum, [md]: { fontSize: 24 } },
  },
  components: {
    MuiCssBaseline: {
      styleOverrides: {
        body: { background: colors.background.default },
        '.num': tnum,
        '.visually-hidden': {
          position: 'absolute', width: 1, height: 1, padding: 0, margin: -1, overflow: 'hidden',
          clip: 'rect(0,0,0,0)', whiteSpace: 'nowrap', border: 0,
        },
        ':focus-visible': { outline: `2px solid ${colors.primary.main}`, outlineOffset: 2 },
        '@media (prefers-reduced-motion: reduce)': {
          '*, *::before, *::after': { transition: 'none !important', animation: 'none !important', scrollBehavior: 'auto !important' },
        },
      },
    },
    MuiButton: {
      defaultProps: { disableElevation: true, variant: 'contained', size: 'large' },
      styleOverrides: {
        root: { borderRadius: radius.base, minHeight: 44, '&:focus-visible': { outline: `2px solid ${colors.primary.main}`, outlineOffset: 2 } },
        sizeLarge: { minHeight: 48 },
      },
    },
    MuiIconButton: { styleOverrides: { root: { minWidth: 44, minHeight: 44 } } },
    MuiTextField: { defaultProps: { variant: 'outlined', fullWidth: true, size: 'medium' } },
    MuiCard: { defaultProps: { variant: 'outlined' }, styleOverrides: { root: { borderRadius: radius.card } } },
    MuiChip: { styleOverrides: { root: { borderRadius: radius.chip, fontWeight: 600 } } },
    MuiDialog: { styleOverrides: { paper: { borderRadius: radius.dialog } } },
    MuiLink: { defaultProps: { underline: 'hover' } },
    MuiSkeleton: { defaultProps: { animation: 'wave' } },
    MuiTableCell: { styleOverrides: { root: { '&.num': { textAlign: 'right', ...tnum } } } },
  },
});
