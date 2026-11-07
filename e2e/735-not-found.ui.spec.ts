import { expect } from '@playwright/test';
import { test, appUrl, e2eHeaders } from './navigation';

test.use({ extraHTTPHeaders: e2eHeaders() });

test.describe('@ui Not found page', () => {
  test('Unknown route shows not found page', async ({ page }) => {
    await page.goto(appUrl);

    // The server only serves known routes, so navigate client-side
    await page.evaluate((url) => {
      history.pushState({}, '', url);
      dispatchEvent(new PopStateEvent('popstate'));
    }, `${appUrl}/does-not-exist`);

    await expect(page.locator('.not-found')).toContainText('Page not found');
    await page.getByRole('link', { name: 'Go to timeline' }).click();
    await expect(page.locator('.not-found')).toBeHidden();
  });
});
