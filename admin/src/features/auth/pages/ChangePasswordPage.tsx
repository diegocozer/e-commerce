import { zodResolver } from '@hookform/resolvers/zod';
import { Button, Stack } from '@mui/material';
import { useForm } from 'react-hook-form';
import { errorMessage } from '@/shared/api/errors';
import { notify, PageHeader } from '@/shared/ui';
import { applyServerErrors, FormSection, RHFTextField } from '@/shared/ui/form';
import { changePassword } from '../api';
import { changePasswordSchema, type ChangePasswordForm } from '../schemas';

export default function ChangePasswordPage() {
  const { control, handleSubmit, setError, reset, formState } = useForm<ChangePasswordForm>({
    resolver: zodResolver(changePasswordSchema),
    defaultValues: { current_password: '', password: '', password_confirmation: '' },
  });
  const onSubmit = handleSubmit(async (v) => {
    try {
      await changePassword(v);
      notify.success('Senha alterada');
      reset();
    } catch (e) {
      const rest = applyServerErrors(e, setError);
      if (!rest.length && !(e as { fieldErrors?: unknown }).fieldErrors) notify.error(errorMessage(e));
    }
  });
  return (
    <>
      <PageHeader title="Alterar senha" />
      <FormSection title="Senha de acesso">
        <Stack component="form" noValidate spacing={4} onSubmit={onSubmit} sx={{ maxWidth: 420 }}>
          <RHFTextField control={control} name="current_password" label="Senha atual" type="password" autoComplete="current-password" required />
          <RHFTextField control={control} name="password" label="Nova senha" type="password" autoComplete="new-password" required helperText="Mínimo 12 caracteres, com maiúscula, minúscula, número e símbolo." />
          <RHFTextField control={control} name="password_confirmation" label="Confirme a nova senha" type="password" autoComplete="new-password" required />
          <Button type="submit" variant="contained" disabled={formState.isSubmitting} sx={{ alignSelf: 'flex-start' }}>
            Alterar senha
          </Button>
        </Stack>
      </FormSection>
    </>
  );
}
