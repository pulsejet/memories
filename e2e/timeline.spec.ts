import { test, expect } from '@playwright/test';
import { login } from './login';

test.beforeEach(login('/'));

test.describe('Open', () => {
  test('Look for Images', async ({ page }) => {
    expect(await page.locator('img[src*="core/preview"]').count(), 'Number of previews').toBeGreaterThan(4);
    await page.waitForTimeout(1000);
  });

  test('Open one image', async ({ page }) => {
    await page.locator('div:nth-child(2) > .p-outer > .img-outer > img').first().click();
    await page.waitForTimeout(1000);
    await page.locator('button.header-close').first().click();
  });

  test('Select two images and delete', async ({ page }) => {
    const i1 = "div:nth-child(2) > div:nth-child(1) > .p-outer";
    const i2 = "div:nth-child(2) > div:nth-child(2) > .p-outer";

    const src1 = await page.locator(`${i1} > .img-outer > img`).first().getAttribute('src');
    const src2 = await page.locator(`${i2} > .img-outer > img`).first().getAttribute('src');

    expect(await page.locator(`img[src="${src1}"]`).count()).toBe(1);
    expect(await page.locator(`img[src="${src2}"]`).count()).toBe(1);

    await page.locator(`${i1}`).hover();
    await page.locator(`${i1} > .select`).click();
    await page.locator(`${i2}`).click();
    await page.waitForTimeout(1000);

    await page.locator('[aria-label="Delete"]').click();
    await page.waitForTimeout(4000);
    expect(await page.locator(`img[src="${src1}"]`).count()).toBe(0);
    expect(await page.locator(`img[src="${src2}"]`).count()).toBe(0);

    // refresh page
    await page.reload();
    await page.waitForSelector('img[src*="core/preview"]');
    expect(await page.locator(`img[src="${src1}"]`).count()).toBe(0);
    expect(await page.locator(`img[src="${src2}"]`).count()).toBe(0);
  });
});