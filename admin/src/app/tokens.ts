/** Tokens de marca (UX §2.2–2.4) — manter idêntico ao storefront. */
export const colors = {
  primary: { main: '#0B4F8A', dark: '#083A66', light: '#E8F1FA', contrastText: '#FFFFFF' },
  secondary: { main: '#C2410C', dark: '#9A3412', light: '#FFF1E8', contrastText: '#FFFFFF' },
  success: { main: '#1B7F3B', light: '#E8F5EC', dark: '#14602C', contrastText: '#FFFFFF' },
  warning: { main: '#B45309', light: '#FFF4E5', dark: '#663C00', contrastText: '#FFFFFF' },
  error: { main: '#C62828', light: '#FDECEC', dark: '#8E1C1C', contrastText: '#FFFFFF' },
  info: { main: '#0277BD', light: '#E6F4FB', dark: '#01579B', contrastText: '#FFFFFF' },
  text: { primary: '#1A2027', secondary: '#4A5563', disabled: '#8A94A3' },
  divider: '#E1E5EB',
  borderInput: '#8A94A3',
  background: { default: '#F5F7FA', paper: '#FFFFFF' },
  dark: {
    background: { default: '#0F141A', paper: '#171E26' },
    text: { primary: '#E6EAF0', secondary: '#A9B3C1' },
    primary: '#6FA8DC',
    secondary: '#F28C4B',
    success: '#5CC985',
    warning: '#F2B45C',
    error: '#F28B82',
    info: '#7CC4F0',
    divider: '#2A3440',
    onAccent: '#0F141A',
  },
} as const;

export const fonts = {
  heading: '"Manrope", system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif',
  body: '"Inter", system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif',
} as const;

export const SIDEBAR_WIDTH = 256;
export const SIDEBAR_COLLAPSED_WIDTH = 72;
