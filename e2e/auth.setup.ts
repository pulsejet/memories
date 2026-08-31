import { test as setup, expect } from '@playwright/test';
import { appUrl, username, password } from './navigation';

const authFile = 'e2e/.state/user.json';

setup('authenticate', async ({ page }) => {
  await page.goto(appUrl);
  await page.locator('#user').fill(username);
  await page.locator('#password').fill(password);
  await page.locator('button[type="submit"]').click();
  await expect(page).toHaveURL(appUrl);
  await page.context().storageState({ path: authFile });
});
