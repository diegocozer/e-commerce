import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import { zodResolver } from '@hookform/resolvers/zod';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import { passwordRule } from '@/features/auth/schemas';
import { PasswordField } from '@/features/auth/components/PasswordField';
import { applyServerErrors } from '@/shared/lib/applyServerErrors';
import { PageHeading } from '@/shared/ui/PageHeading';
import { useSnackbar } from '@/shared/ui/Snackbar';
import { changePassword } from '../api';

const schema = z
  .object({ current_password: z.string().min(1, 'Informe a senha atual'), password: passwordRule, password_confirmation: z.string() })
  .refine((v) => v.password === v.password_confirmation, { path: ['password_confirmation'], message: 'As senhas não coincidem' })
  .refine((v) => v.password !== v.current_password, { path: ['password'], message: 'A nova senha deve ser diferente da atual' });
type Values = z.infer<typeof schema>;

export default function PasswordPage() {
  const notify = useSnackbar();
  const [top, setTop] = useState<string[]>([]);
  const { control, handleSubmit, setError, reset, formState } = useForm<Values>({ resolver: zodResolver(schema), defaultValues: { current_password: '', password: '', password_confirmation: '' }, mode: 'onBlur' });
  const onSubmit = handleSubmit(async (v) => {
    setTop([]);
    try {
      await changePassword(v);
      notify('Senha alterada', { severity: 'success' });
      reset();
    } catch (err) {
      setTop(applyServerErrors(err, setError, ['current_password', 'password', 'password_confirmation']));
    }
  });
  const field = (name: keyof Values, label: string, ac: 'current-password' | 'new-password') => (
    <Controller name={name} control={control} render={({ field: f, fieldState }) => <PasswordField {...f} label={label} required autoComplete={ac} error={Boolean(fieldState.error)} helperText={fieldState.error?.message} />} />
  );
  return (
    <Box>
      <PageHeading>Senha</PageHeading>
      <Card sx={{ p: 4, maxWidth: 480 }}>
        <Box component="form" onSubmit={onSubmit} noValidate sx={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
          {top.length ? <Alert severity="error">{top.join(' ')}</Alert> : null}
          {field('current_password', 'Senha atual', 'current-password')}
          {field('password', 'Nova senha', 'new-password')}
          {field('password_confirmation', 'Confirmar nova senha', 'new-password')}
          <Button type="submit" loading={formState.isSubmitting}>Alterar senha</Button>
        </Box>
      </Card>
    </Box>
  );
}
