import { createTheme } from '@mui/material/styles';
import { colors, fonts } from './tokens';

const d = colors.dark;

/** Tema do painel: CSS variables + esquemas claro/escuro (UX §2.2/§2.6), densidade small. */
export const theme = createTheme({
  cssVariables: { colorSchemeSelector: 'data-mui-color-scheme' },
  colorSchemes: {
    light: {
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
      },
    },
    dark: {
      palette: {
        primary: { main: d.primary, contrastText: d.onAccent, light: '#1C3550', dark: '#4F8FCB' },
        secondary: { main: d.secondary, contrastText: d.onAccent },
        success: { main: d.success, contrastText: d.onAccent },
        warning: { main: d.warning, contrastText: d.onAccent },
        error: { main: d.error, contrastText: d.onAccent },
        info: { main: d.info, contrastText: d.onAccent },
        text: d.text,
        divider: d.divider,
        background: d.background,
      },
    },
  },
  spacing: 4,
  shape: { borderRadius: 8 },
  typography: {
    fontFamily: fonts.body,
    fontSize: 14,
    h1: { fontFamily: fonts.heading, fontWeight: 800, fontSize: '1.5rem', lineHeight: 1.2 },
    h2: { fontFamily: fonts.heading, fontWeight: 700, fontSize: '1.25rem', lineHeight: 1.25 },
    h3: { fontFamily: fonts.heading, fontWeight: 700, fontSize: '1.0625rem', lineHeight: 1.3 },
    h4: { fontWeight: 600, fontSize: '1rem', lineHeight: 1.35 },
    h5: { fontWeight: 600, fontSize: '0.9375rem' },
    h6: { fontWeight: 600, fontSize: '0.875rem' },
    button: { textTransform: 'none', fontWeight: 600 },
    overline: { fontWeight: 600, letterSpacing: '0.08em', fontSize: '0.6875rem' },
  },
  components: {
    MuiCssBaseline: {
      styleOverrides: {
        '.num': { fontVariantNumeric: 'tabular-nums' },
        '@media (prefers-reduced-motion: reduce)': {
          '*, *::before, *::after': { transitionDuration: '0.01ms !important', animationDuration: '0.01ms !important' },
        },
        ':focus-visible': { outlineOffset: 2 },
      },
    },
    MuiButton: { defaultProps: { disableElevation: true, size: 'small' } },
    MuiIconButton: { defaultProps: { size: 'small' } },
    MuiTextField: { defaultProps: { size: 'small', fullWidth: true, variant: 'outlined' } },
    MuiFormControl: { defaultProps: { size: 'small' } },
    MuiSelect: { defaultProps: { size: 'small' } },
    MuiAutocomplete: { defaultProps: { size: 'small' } },
    MuiTable: { defaultProps: { size: 'small' } },
    MuiListItem: { defaultProps: { dense: true } },
    MuiCard: { defaultProps: { variant: 'outlined' }, styleOverrides: { root: { borderRadius: 12 } } },
    MuiPaper: { defaultProps: { elevation: 0 } },
    MuiChip: { styleOverrides: { root: { fontWeight: 600 } } },
    MuiDialog: { styleOverrides: { paper: { borderRadius: 16 } } },
    MuiLink: { defaultProps: { underline: 'hover' } },
    MuiTableCell: { styleOverrides: { head: { fontWeight: 600, whiteSpace: 'nowrap' } } },
  },
});
