// UX §2.2–2.4 — mantido idêntico ao do painel.
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
  header: '#0B4F8A',
  footer: '#0F172A',
  footerText: '#E2E8F0',
  focusOnPrimary: '#FFB74D',
} as const;

export const fonts = {
  heading: '"Manrope", system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif',
  body: '"Inter", system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif',
} as const;

export const radius = { base: 8, card: 12, chip: 16, dialog: 16 } as const;
export const maxContentWidth = 1280;
