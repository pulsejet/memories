import { test, expect } from "@playwright/test";
import { login } from "./login";

test.beforeEach(login("/folders"));

test.describe("Open", () => {
  test("Look for Folders", async ({ page }) => {
    const ct = await page.locator(".big-icon").count();
    expect(ct, "Number of folders").toBe(2);
  });

  test("Open folder", async ({ page }) => {
    await page.locator("text=Local").click();
    await page.waitForTimeout(2000);
    await page.waitForSelector("img.ximg");
  });
});
