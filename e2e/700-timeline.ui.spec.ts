import { test, expect } from '@playwright/test';
import { appUrl, bootstrap } from './navigation';

test.describe('@ui Timeline feed and photo preview', () => {
  test.beforeEach(async ({ page }) => {
    await bootstrap(page);
    await page.goto(appUrl);
    await page.waitForSelector('.img-outer');
    await page.waitForTimeout(500);
  });

  test('Look for Images', async ({ page }) => {
    expect(await page.locator('.img-outer').count(), 'Number of previews').toBeGreaterThan(4);
  });

  test('Open one image', async ({ page }) => {
    await page.locator('.img-outer').first().click();
    await page.waitForTimeout(1000);
    await page.locator('button[title="Close"]').first().click();
  });
});
