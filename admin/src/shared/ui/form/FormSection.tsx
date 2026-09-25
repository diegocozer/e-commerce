import { Card, CardContent, Stack, Typography } from '@mui/material';
import type { ReactNode } from 'react';

/** Seção de formulário em Card com âncora (UX §5.5). */
export function FormSection({ id, title, description, actions, children }: { id?: string; title: string; description?: ReactNode; actions?: ReactNode; children: ReactNode }) {
  return (
    <Card id={id} component="section" aria-labelledby={id ? `${id}-title` : undefined} sx={{ scrollMarginTop: 120 }}>
      <CardContent sx={{ p: 5 }}>
        <Stack direction="row" sx={{ justifyContent: 'space-between', alignItems: 'flex-start', mb: 4 }} spacing={2}>
          <div>
            <Typography variant="h3" component="h2" id={id ? `${id}-title` : undefined}>
              {title}
            </Typography>
            {description && (
              <Typography variant="body2" color="text.secondary" sx={{ mt: 1 }}>
                {description}
              </Typography>
            )}
          </div>
          {actions}
        </Stack>
        {children}
      </CardContent>
    </Card>
  );
}
