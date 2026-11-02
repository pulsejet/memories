import { expect, devices } from '@playwright/test';
import { test, appUrl, e2eHeaders } from './navigation';

test.use({ extraHTTPHeaders: e2eHeaders() });

test.describe('@ui Mobile bottom navigation', () => {
  test.use({
    viewport: devices['Pixel 7'].viewport,
    hasTouch: true,
    isMobile: true,
    userAgent: devices['Pixel 7'].userAgent,
    deviceScaleFactor: devices['Pixel 7'].deviceScaleFactor,
  });

  test('Bottom nav replaces sidebar navigation', async ({ page }) => {
    await page.goto(appUrl);

    const nav = page.locator('#mobile-nav');
    await expect(nav).toBeVisible();
    await expect(page.locator('#content-vue > .app-navigation')).toBeHidden();
    await expect(nav.getByRole('link', { name: 'Photos' })).toHaveClass(/router-link-exact-active/);

    await nav.getByRole('link', { name: 'Explore' }).click();
    await expect(page).toHaveURL(/\/explore/);
    await expect(nav.getByRole('link', { name: 'Explore' })).toHaveClass(/router-link-exact-active/);
  });
});
