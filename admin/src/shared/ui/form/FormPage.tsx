import { Box, Button, CircularProgress, Link, List, ListItemButton, Paper, Stack, Typography } from '@mui/material';
import { useEffect, type FormEvent, type ReactNode } from 'react';
import { PageHeader } from '../PageHeader';
import { useUnsavedChangesGuard } from '@/shared/hooks/useUnsavedChangesGuard';
import { UnsavedChangesDialog } from './UnsavedChangesDialog';

export interface FormPageSection {
  id: string;
  label: string;
  hasError?: boolean;
}

interface Props {
  title: string;
  back?: { to: string; label: string };
  sections?: FormPageSection[];
  isDirty: boolean;
  isSubmitting: boolean;
  onSubmit: (e?: FormEvent) => void;
  onDiscard?: () => void;
  saveLabel?: string;
  readOnly?: boolean;
  headerExtra?: ReactNode;
  children: ReactNode;
}

/**
 * Padrão de formulário (UX §5.5): cabeçalho sticky com Descartar/Salvar, índice de seções
 * com marcação de erro, Ctrl+S, guarda de saída com formulário sujo.
 */
export function FormPage({ title, back, sections, isDirty, isSubmitting, onSubmit, onDiscard, saveLabel = 'Salvar', readOnly, headerExtra, children }: Props) {
  const blocker = useUnsavedChangesGuard(isDirty && !isSubmitting && !readOnly);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
        e.preventDefault();
        if (isDirty && !isSubmitting && !readOnly) onSubmit();
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [isDirty, isSubmitting, readOnly, onSubmit]);

  return (
    <Box component="form" noValidate onSubmit={onSubmit}>
      <PageHeader title={title} back={back} chips={headerExtra} />
      {!readOnly && (
        <Paper
          sx={{
            position: 'sticky',
            top: 56,
            zIndex: 5,
            mb: 4,
            px: 4,
            py: 2,
            display: 'flex',
            justifyContent: 'flex-end',
            gap: 2,
            border: 1,
            borderColor: 'divider',
          }}
        >
          <Typography variant="body2" color="text.secondary" sx={{ mr: 'auto', alignSelf: 'center' }}>
            * obrigatório {isDirty ? '· alterações não salvas' : ''}
          </Typography>
          {onDiscard && (
            <Button onClick={onDiscard} disabled={!isDirty || isSubmitting}>
              Descartar
            </Button>
          )}
          <Button type="submit" variant="contained" disabled={!isDirty || isSubmitting} startIcon={isSubmitting ? <CircularProgress size={14} color="inherit" /> : undefined}>
            {saveLabel}
          </Button>
        </Paper>
      )}
      <Stack direction="row" spacing={4} sx={{ alignItems: 'flex-start' }}>
        {sections && sections.length > 1 && (
          <Box component="nav" aria-label="Seções do formulário" sx={{ width: 180, flexShrink: 0, position: 'sticky', top: 128, display: { xs: 'none', lg: 'block' } }}>
            <Typography variant="overline" color="text.secondary">
              Seções
            </Typography>
            <List dense>
              {sections.map((s) => (
                <ListItemButton key={s.id} component={Link} href={`#${s.id}`} sx={{ borderRadius: 1 }}>
                  {s.hasError && (
                    <Box component="span" sx={{ color: 'error.main', mr: 1 }} aria-label="com erro">
                      ●
                    </Box>
                  )}
                  {s.label}
                </ListItemButton>
              ))}
            </List>
          </Box>
        )}
        <Stack spacing={4} sx={{ flex: 1, minWidth: 0 }}>
          {children}
        </Stack>
      </Stack>
      <UnsavedChangesDialog blocker={blocker} />
    </Box>
  );
}
