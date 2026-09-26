import { test as setup } from '@playwright/test';
import { ADMIN_STATE, login } from './support/helpers';

setup('sessão do super-admin', async ({ page }) => {
  await login(page);
  await page.context().storageState({ path: ADMIN_STATE });
});
