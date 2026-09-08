import { test, expect } from '@playwright/test';
import { appUrl, bootstrap, e2eHeaders, teardown } from './navigation';

test.beforeEach(bootstrap);
test.afterEach(teardown);

test.use({ extraHTTPHeaders: e2eHeaders() });

test.describe('@ui Videos view', () => {
  test('Empty videos view', async ({ page }) => {
    await page.goto(`${appUrl}/videos`);
    await expect(page).toHaveURL(`${appUrl}/videos`);
    await expect(page.getByText('Your videos will appear here')).toBeVisible();
  });
});

test.describe('@ui This day view', () => {
  test('This day loads', async ({ page }) => {
    await page.goto(`${appUrl}/thisday`);
    await expect(page).toHaveURL(`${appUrl}/thisday`);
    await expect(page.getByText('Memories from past years will appear here')).toBeVisible();
  });
});

test.describe('@ui Explore view', () => {
  test('Explore shows navigation links', async ({ page }) => {
    await page.goto(`${appUrl}/explore`);
    await expect(page).toHaveURL(`${appUrl}/explore`);
    await expect(page.getByText('Explore').first()).toBeVisible();

    for (const name of ['Folders', 'Favorites', 'Videos', 'Archive', 'On this day', 'Map']) {
      // NcButton with `to` renders a real link in Nextcloud Vue 9
      await expect(page.locator('.explore-outer').getByRole('link', { name })).toBeVisible();
    }
  });
});

test.describe('@ui Places view', () => {
  test.skip(!!process.env.NO_PLANET_DB, 'Skipping places UI: NO_PLANET_DB is set');

  test('Places clusters load', async ({ page }) => {
    await page.goto(`${appUrl}/places`);
    await expect(page).toHaveURL(`${appUrl}/places`);
    await expect(page.locator('.cluster').first()).toBeVisible();
  });
});
