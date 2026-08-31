import { test as setup, expect } from '@playwright/test';
import { baseUrl, username, password, ocsHeaders } from './navigation';

const authFile = 'e2e/.state/user.json';

setup('authenticate', async ({ request }) => {
  const res = await request.post(`${baseUrl}/index.php/login`, {
    headers: {
      ...ocsHeaders,
      origin: baseUrl,
    },
    form: {
      user: username,
      password: password,
    },
  });
  expect(res.ok()).toBeTruthy();
  expect(await res.text()).toContain('data-user-displayname');
  await request.storageState({ path: authFile });
});
