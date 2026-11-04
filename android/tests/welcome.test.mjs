import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const html = readFileSync(new URL('../app/src/main/assets/welcome.html', import.meta.url), 'utf8');
const script = html.match(/<script>([\s\S]*?)<\/script>/)[1];

function welcome(fetchImpl = async () => ({ ok: true })) {
  const elements = new Map([...html.matchAll(/\bid="([^"]+)"/g)].map(([, id]) => [id, {
    value: '', checked: false, disabled: false, textContent: '', style: {}, events: {},
    classList: { add() {}, remove() {} },
    addEventListener(name, callback) { this.events[name] = callback; },
  }]));
  const timeouts = new Map();
  const requests = [];
  let timeout;
  vm.runInNewContext(script, {
    document: { getElementById: id => elements.get(id) },
    URL, AbortController,
    setTimeout(callback, ms) { timeout = ms; timeouts.set(1, callback); return 1; },
    clearTimeout(id) { timeouts.delete(id); },
    fetch: async (url, options) => { requests.push(url); return fetchImpl(url, options); },
  });
  const element = id => elements.get(id);
  return {
    element, requests, timeouts, timeout: () => timeout,
    setUrl(value) { element('server-url').value = value; element('server-url').events.input(); },
    login: () => element('login').events.click(),
  };
}

test('client certificates are available only for valid HTTPS addresses', () => {
  const page = welcome();
  assert.equal(page.element('login').disabled, true);
  assert.equal(page.element('client-certificate').disabled, true);
  page.setUrl('cloud.example.com');
  assert.equal(page.element('client-certificate').disabled, false);
  page.element('client-certificate').checked = true;
  page.setUrl('http://cloud.example.com');
  assert.equal(page.element('client-certificate').disabled, true);
  assert.equal(page.element('client-certificate').checked, false);
  assert.equal(page.element('login').disabled, false);
});

test('ordinary login preserves its existing timeout and sends no certificate request', async () => {
  const page = welcome();
  page.setUrl('cloud.example.com');
  await page.login();
  assert.match(page.requests[0], /trustAll=0&clientCertificate=0$/);
  assert.equal(page.timeout(), 5000);
  assert.equal(page.timeouts.size, 0);
});

test('certificate login allows time for the picker and retains server verification', async () => {
  let finish;
  const page = welcome(() => new Promise(resolve => { finish = resolve; }));
  page.setUrl('https://cloud.example.com:8443/nextcloud');
  page.element('client-certificate').checked = true;
  const login = page.login();
  assert.equal(page.element('server-url').disabled, true);
  assert.equal(page.element('client-certificate').disabled, true);
  assert.equal(page.element('trust-all').disabled, true);
  assert.equal(page.element('login-text').textContent, 'Select a certificate');
  assert.match(page.requests[0], /trustAll=0&clientCertificate=1$/);
  assert.equal(page.timeout(), 180000);
  finish({ ok: true });
  await login;
  assert.equal(page.element('client-certificate').disabled, false);
  assert.equal(page.element('client-certificate').checked, true);
  assert.equal(page.element('trust-all').disabled, false);
  assert.equal(page.timeouts.size, 0);
});

test('picker cancellation shows the error, clears the timer and permits retry', async () => {
  const page = welcome(async () => ({ ok: false, json: async () => ({ message: 'No client certificate selected' }) }));
  page.setUrl('cloud.example.com');
  page.element('client-certificate').checked = true;
  await page.login();
  assert.match(page.element('err').textContent, /No client certificate selected/);
  assert.equal(page.element('login').disabled, false);
  assert.equal(page.element('server-url').disabled, false);
  assert.equal(page.element('client-certificate').disabled, false);
  assert.equal(page.timeouts.size, 0);
});

test('client authentication can coexist with an explicit server-verification opt-out', async () => {
  const page = welcome();
  page.setUrl('cloud.example.com');
  page.element('client-certificate').checked = true;
  page.element('trust-all').checked = true;
  await page.login();
  assert.match(page.requests[0], /trustAll=1&clientCertificate=1$/);
});
