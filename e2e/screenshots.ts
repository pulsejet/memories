import { test, type Page, type TestStepInfo } from '@playwright/test';

/**
 * Save a viewport screenshot for manual comparison after major changes.
 * Only use mid-test where the view still changes afterwards; every test
 * already gets an automatic end-of-test screenshot. The file is kept at
 * a stable per-test path and also attached to the report (to the step
 * when given). Call only once the view has settled.
 */
export async function snap(page: Page, name: string, step?: TestStepInfo): Promise<void> {
  const filePath = test.info().outputPath(`snap-${name}.png`);
  const body = await page.screenshot({ path: filePath });
  if (step) {
    await step.attach(name, { body, contentType: 'image/png' });
  } else {
    await test.info().attach(name, { path: filePath });
  }
}
