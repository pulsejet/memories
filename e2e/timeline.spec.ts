import { test, expect } from '@playwright/test';
import { login } from './login';

test.beforeEach(login('/'));

test.describe('Open', () => {
  test.beforeEach(async ({ page }) => {
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

  test('Select two images and delete', async ({ page }) => {
    const src1 = await page.locator('.img-outer > img').nth(1).getAttribute('src');
    const src2 = await page.locator('.img-outer > img').nth(2).getAttribute('src');

    expect(await page.locator(`img[src="${src1}"]`).count()).toBe(1);
    expect(await page.locator(`img[src="${src2}"]`).count()).toBe(1);

    await page.locator('.img-outer').nth(1).hover();
    await page.locator('.p-outer > .select').nth(1).click();
    await page.locator('.img-outer').nth(2).click();
    await page.waitForTimeout(1000);

    await page.locator('[aria-label="Delete"]').click();
    await page.waitForTimeout(1000);
    await page
      .locator(
        [
          '.oc-dialog button.error', // NC <=29
          '.dialog .button-vue--vue-primary', // NC >=30
        ].join(', '),
      )
      .click();
    await page.waitForTimeout(2000);
    expect(await page.locator(`img[src="${src1}"]`).count()).toBe(0);
    expect(await page.locator(`img[src="${src2}"]`).count()).toBe(0);
  });
});
