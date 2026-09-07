import { test, expect } from '@playwright/test';
import { appUrl, bootstrap, e2eHeaders, teardown } from './navigation';

test.beforeEach(bootstrap);
test.afterEach(teardown);

test.use({
  extraHTTPHeaders: e2eHeaders({
    timelinePath: '/for-onthisday',
  }),
});

const YEAR_TEXTS = [
  '1 year ago',
  '2 years ago',
  '3 years ago',
  '4 years ago',
  '5 years ago',
  '6 years ago',
];

test.describe('@ui On this day carousel', () => {
  // The 2-years-ago photos are on Jul 30 and Aug 1, matched by the default day range.
  test('Top carousel shows past years', async ({ page }) => {
    await page.goto(appUrl);

    const groups = page.locator('.dtm-container .outer .group');
    await expect(groups).toHaveCount(YEAR_TEXTS.length);
    for (const [i, text] of YEAR_TEXTS.entries()) {
      await expect(groups.nth(i).locator('.overlay')).toHaveText(text);
    }
  });

  test('Scroll carousel with arrows', async ({ page }) => {
    await page.goto(appUrl);

    const carousel = page.locator('.dtm-container .outer');
    const groups = carousel.locator('.group');
    await expect(groups).toHaveCount(YEAR_TEXTS.length);
    await expect(carousel.getByRole('button', { name: 'Move right' })).toBeVisible();
    await expect(carousel.getByRole('button', { name: 'Move left' })).toHaveCount(0);
    await expect(groups.nth(YEAR_TEXTS.length - 1)).not.toBeInViewport();

    await carousel.getByRole('button', { name: 'Move right' }).click();
    await expect(carousel.getByRole('button', { name: 'Move left' })).toBeVisible();
    await expect(groups.nth(YEAR_TEXTS.length - 1)).toBeInViewport();

    await carousel.getByRole('button', { name: 'Move left' }).click();
    await expect(carousel.getByRole('button', { name: 'Move left' })).toHaveCount(0);
  });

  test('Open year group in viewer', async ({ page }) => {
    await page.goto(appUrl);
    await page.locator('.dtm-container .outer .group').first().click();
    await page.waitForSelector('body.viewer-fully-opened');
  });
});
