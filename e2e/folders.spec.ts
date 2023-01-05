import { test, expect } from "@playwright/test";
import { login } from "./login";

test.beforeEach(login("/folders"));

test.describe("Open", () => {
  test("Look for Folders", async ({ page }) => {
    expect(await page.locator(".big-icon").count(), "Number of folders").toBe(
      2
    );
  });

  test("Open folder", async ({ page }) => {
    await page.locator("text=Local").click();
    await page.waitForSelector('img[src*="api/image/preview"]');
  });
});
