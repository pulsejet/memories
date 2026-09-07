import { test, expect } from '@playwright/test';
import type { Page } from '@playwright/test';
import { appUrl, bootstrap, e2eHeaders, teardown } from './navigation';
import { DavClient } from './utils';
import { snap } from './screenshots';

test.beforeEach(bootstrap);
test.afterEach(teardown);

test.use({
  extraHTTPHeaders: e2eHeaders({
    timelinePath: '/for-geo',
  }),
});

// Desktop only: selection relies on hover check icons, mouse pointer
// events and shift modifiers (only a Desktop Chrome project exists).
test.describe('@ui Photo selection', () => {
  // Six Sydney photos spanning Nov 13-14 2023, latest first.
  // Display order follows the dataset timestamps:
  // Nov 14 (dayid 19675): 100 (15:00), 099 (12:15), 098 (09:30);
  // Nov 13 (dayid 19674): 097 (15:00), 096 (12:30), 095 (10:00).
  const ordered = [
    'for-geo-100.jpg',
    'for-geo-099.jpg',
    'for-geo-098.jpg',
    'for-geo-097.jpg',
    'for-geo-096.jpg',
    'for-geo-095.jpg',
  ];
  const ids: number[] = [];
  let lastId: number;

  test.beforeAll(async ({ request }) => {
    const dav = new DavClient(request);
    for (const name of ordered) {
      ids.push(await dav.fileid(`/for-geo/${name}`));
    }
    // Oldest photo in the dataset: Los Angeles, May 10 2018 09:15.
    lastId = await dav.fileid('/for-geo/for-geo-001.jpg');
  });

  test('Select with check, click and shift+click', async ({ page }) => {
    await openTimeline(page);

    await test.step('Check icon selects one image', async (step) => {
      await checkIcon(page, 0);
      await expectSelection(page, [true, false, false, false, false, false]);
      await page.waitForTimeout(200); // animation
      await snap(page, 'selection-single', step);
    });

    await test.step('Check icon on another adds to selection', async () => {
      await checkIcon(page, 2);
      await expectSelection(page, [true, false, true, false, false, false]);
    });

    await test.step('Click on another image adds to selection', async () => {
      await page.locator(`.p-outer--${ids[1]} .img-outer`).click();
      await expectSelection(page, [true, true, true, false, false, false]);
      // Clicking must toggle selection, not open the viewer.
      await expect(page.locator('body.viewer-fully-opened')).toHaveCount(0);
    });

    await test.step('Shift+click across days selects subset in between', async (step) => {
      await page.keyboard.down('Shift');
      await page.locator(`.p-outer--${ids[4]} .img-outer`).click();
      await page.keyboard.up('Shift');
      // Everything from ids[0] down to ids[4] across the day boundary,
      // but ids[5] stays unselected.
      await expectSelection(page, [true, true, true, true, true, false]);
      await page.waitForTimeout(200); // animation
      await snap(page, 'selection-range', step);
    });

    await test.step('Shift+click backwards selects subset in between', async () => {
      // Clear the selection by unchecking everything (no reload:
      // it restores scroll mid-timeline and churns the recycler).
      for (let i = 0; i < 5; i++) {
        await checkIcon(page, i);
      }
      await expectSelection(page, [false, false, false, false, false, false]);

      await checkIcon(page, 5);
      await expectSelection(page, [false, false, false, false, false, true]);

      await page.keyboard.down('Shift');
      await page.locator(`.p-outer--${ids[1]} .img-outer`).click();
      await page.keyboard.up('Shift');
      // Everything from ids[1] down to ids[5] across the day boundary,
      // but ids[0] stays unselected.
      await expectSelection(page, [false, true, true, true, true, true]);
    });

    await test.step('Click on selected image unselects it', async () => {
      await page.locator(`.p-outer--${ids[3]} .img-outer`).click();
      await expectSelection(page, [false, true, true, false, true, true]);
    });

    await test.step('Shift+click last image after smooth scroll selects all in between', async (step) => {
      // Start over with just the 2nd image of the timeline.
      for (const i of [1, 2, 4, 5]) {
        await checkIcon(page, i);
      }
      await expectSelection(page, [false, false, false, false, false, false]);

      await checkIcon(page, 1);

      // Scroll smoothly right to the end so every day in between loads.
      const lastSelector = `.p-outer--${lastId}`;
      for (let i = 0; i < 60; i++) {
        if ((await page.locator(lastSelector).count()) > 0) break;
        await page.mouse.wheel(0, 1500);
        await page.waitForTimeout(250);
      }
      await expect(page.locator(lastSelector)).toBeVisible();

      await page.keyboard.down('Shift');
      await page.locator(`${lastSelector} .img-outer`).click();
      await page.keyboard.up('Shift');

      // Everything from the 2nd image down to the last: 99 photos.
      // Detached rows are not in the DOM, so only the top bar count is asserted.
      await expect(page.locator('.memories-top-bar .text')).toContainText('99 selected');
      await page.waitForTimeout(200); // animation
      await snap(page, 'selection-all', step);
    });
  });

  test('Select entire day from header', async ({ page }) => {
    await openTimeline(page);

    await test.step('Click on day header selects the entire day', async (step) => {
      await page.locator('.head-row--19675 .main').click();
      await expectSelection(page, [true, true, true, false, false, false]);
      await expect(page.locator('.head-row--19675')).toHaveClass(/selected/);
      await expect(page.locator('.head-row--19674')).not.toHaveClass(/selected/);
      await page.waitForTimeout(200); // animation
      await snap(page, 'selection-day', step);
    });

    await test.step('Click on day header again unselects the day', async () => {
      await page.locator('.head-row--19675 .main').click();
      await expectSelection(page, [false, false, false, false, false, false]);
      await expect(page.locator('.head-row--19675')).not.toHaveClass(/selected/);
    });
  });

  async function expectSelection(page: Page, selected: boolean[]) {
    for (const [i, isSelected] of selected.entries()) {
      const photo = page.locator(`.p-outer--${ids[i]}`);
      if (isSelected) {
        await expect(photo).toHaveClass(/selected/);
      } else {
        await expect(photo).not.toHaveClass(/selected/);
      }
    }
    await expect(page.locator('.p-outer.selected')).toHaveCount(selected.filter(Boolean).length);

    const count = selected.filter(Boolean).length;
    if (count > 0) {
      await expect(page.locator('.memories-top-bar .text')).toContainText(`${count} selected`);
    } else {
      await expect(page.locator('.memories-top-bar')).toHaveCount(0);
    }
  }

  async function openTimeline(page: Page) {
    await page.goto(appUrl);
    // Scroll once to the middle of the group and wait until the recycler
    // has settled with all six photos attached. Further scrolling churns
    // the recycler and detaches them again.
    const selectors = ids.map((id) => `.p-outer--${id}`);
    await page.locator(selectors[2]).scrollIntoViewIfNeeded();
    await page.waitForFunction(
      (sels) => sels.every((s) => !!document.querySelector(s)),
      selectors,
    );
    for (const selector of selectors) {
      await expect(page.locator(selector)).toBeVisible();
    }
  }

  async function checkIcon(page: Page, i: number) {
    // The check icon is only displayed on hover, like for a real user.
    await page.locator(`.p-outer--${ids[i]}`).hover();
    await page.locator(`.p-outer--${ids[i]} .select`).click();
  }
});
