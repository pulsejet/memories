import { test, expect } from '@playwright/test';
import { appUrl, bootstrap, e2eHeaders, teardown } from './navigation';
import { DavClient } from './utils';

test.beforeEach(bootstrap);
test.afterEach(teardown);

test.use({
  extraHTTPHeaders: e2eHeaders({
    timelinePath: '/for-geo',
  }),
});

// Desktop only: the scroller is hover-driven (only a Desktop Chrome project exists).
test.describe('@ui Timeline scroller', () => {
  let id021: number;
  let id041: number;

  test.beforeAll(async ({ request }) => {
    const dav = new DavClient(request);
    // San Francisco, Jun 1 2019 and Paris, Aug 5 2021.
    id021 = await dav.fileid('/for-geo/for-geo-021.jpg');
    id041 = await dav.fileid('/for-geo/for-geo-041.jpg');
  });

  test('Hover, click and follow the scroller', async ({ page }) => {
    await page.goto(appUrl);
    await page.locator('.p-outer:not(.placeholder)').first().waitFor();
    await expect(page.locator('.scroller .tick', { hasText: '2019' })).toBeVisible();

    const scroller = page.locator('.scroller');
    const box = (await scroller.boundingBox())!;
    const x = box.x + box.width / 2;

    await test.step('Hover above 2019 shows Jun 2019', async () => {
      const tick2019 = page.locator('.scroller .tick', { hasText: '2019' });
      const tickBox = (await tick2019.boundingBox())!;
      await page.mouse.move(x, tickBox.y - 8);
      await expect(page.locator('.scroller .cursor.hv .text')).toHaveText('Jun 2019');
    });

    await test.step('Click above 2019 jumps to 2019 images', async () => {
      const tick2019 = page.locator('.scroller .tick', { hasText: '2019' });
      const tickBox = (await tick2019.boundingBox())!;
      await page.mouse.click(x, tickBox.y - 8);
      await expect(page.locator(`.p-outer--${id021}`)).toBeInViewport({ timeout: 15000 });
    });

    await test.step('Scroll up to 2021 shows Aug 2021 on the cursor', async () => {
      // Move off the scroller so the cursor follows the recycler, not the mouse.
      await page.mouse.move(640, 360);

      // 2021 was jumped over, so scroll up until it renders.
      const sel041 = `.p-outer--${id041}`;
      for (let i = 0; i < 60; i++) {
        if ((await page.locator(sel041).count()) > 0) break;
        await page.locator('.recycler').evaluate((el) => {
          el.scrollTop -= 500;
        });
        await page.waitForTimeout(100);
      }
      await page.locator(sel041).scrollIntoViewIfNeeded();
      await expect(page.locator(sel041)).toBeInViewport();
      await expect(page.locator('.scroller .cursor.hv .text')).toContainText('Aug 2021', {
        timeout: 15000,
      });
    });
  });
});

test.describe('@ui Timeline top matter', () => {
  test('Top matter resize keeps rows in place when scrolled past it', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 400 });
    await page.goto(appUrl);
    await page.locator('.p-outer:not(.placeholder)').first().waitFor();

    const recycler = page.locator('.recycler');
    const before = page.locator('.recycler-before');

    // Scroll past the top matter, and let the start offset flush.
    const beforeHeight = (await before.boundingBox())!.height;
    await recycler.evaluate((el, top) => (el.scrollTop = top), beforeHeight + 100);
    await expect.poll(() => recycler.evaluate((el) => el.scrollTop)).toBe(beforeHeight + 100);

    // Pick a visible row to track through the resizes.
    const row = page.locator('.p-outer:not(.placeholder):visible').nth(2);
    await row.waitFor();
    const key = await row.evaluate((el) => [...el.classList].find((c) => c.startsWith('p-outer--')));
    const photo = page.locator(`.${key}:visible`);
    const rowY = async () => (await photo.boundingBox())!.y;
    const y = await rowY();

    await test.step('Growing keeps the row in place', async () => {
      await before.evaluate((el) => (el.style.paddingBottom = '60px'));
      await expect.poll(async () => (await before.boundingBox())!.height - beforeHeight).toBe(60);
      await page.waitForTimeout(100);
      expect(await rowY()).toBeCloseTo(y, 0);
    });

    await test.step('Shrinking keeps the row in place', async () => {
      await before.evaluate((el) => (el.style.paddingBottom = ''));
      await page.waitForTimeout(100);
      expect(await rowY()).toBeCloseTo(y, 0);
    });
  });
});
