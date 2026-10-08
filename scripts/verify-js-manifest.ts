#!/usr/bin/env -S npx tsx
/**
 * Verifies the build manifest after `npm run build`.
 *
 * Consumes only the DSSE envelope (`memories-manifest.dsse.json`, sigstore
 * `Envelope` type), which carries the TUF targets payload plus its signature:
 *   1. Authenticity: the DSSE signature must verify over the pre-auth
 *      encoding of the payload against a pinned key. Catches tampering.
 *   2. Freshness: the TUF targets must not be expired.
 *   3. Content transparency: sha256 and length of every listed file must
 *      match its target. Catches stale files and truncated downloads.
 *
 * Usage:
 *   npx tsx scripts/verify-js-manifest.ts         # dev/default key
 *   npx tsx scripts/verify-js-manifest.ts --prod  # prod key pinned in the native apps
 *
 * Exit code is 0 when everything verifies, 1 on any failure.
 */
import * as nodeCrypto from 'crypto';
import * as nodeFs from 'fs';
import * as nodePath from 'path';
import { dsse } from '@sigstore/core';
import { Envelope } from '@sigstore/protobuf-specs';
import { Targets } from '@tufjs/models';

// Dev key; mirrors the manifest plugin seed.
const DEV_PUBLIC_KEY = 'MIJxGvOr0LMg9Isyfi5S4cHQsP+v4mFzrsmY2AYKOjs=';

// Prod key pinned in the native apps.
const PROD_PUBLIC_KEY = 'aA2fpqrrJHTiCkE9ecQNDR5V1qO7oHppjdJQ7Pj5nhQ=';

// Entries that must always be present; guards against an empty or
// truncated manifest passing on signature alone.
const REQUIRED_ENTRIES = [
  'memories-main.js',
  'memories-admin.js',
  'memories-hooks-clear-cache.js',
  'memories-service-worker.js',
];

// Key selection: --prod verifies what release builds ship to native clients.
const IS_PROD = process.argv.includes('--prod');
const PUBKEY = IS_PROD ? PROD_PUBLIC_KEY : DEV_PUBLIC_KEY;

/** Imports a raw 32-byte base64 Ed25519 public key via JWK (avoids hand-rolled DER). */
function importPublicKey(base64: string): nodeCrypto.KeyObject {
  const x = Buffer.from(base64, 'base64').toString('base64url');
  return nodeCrypto.createPublicKey({
    key: { kty: 'OKP', crv: 'Ed25519', x },
    format: 'jwk',
  });
}

/** Hex sha256 of a file's raw bytes. */
function sha256File(path: string): string {
  return nodeCrypto.createHash('sha256').update(nodeFs.readFileSync(path)).digest('hex');
}

// The DSSE envelope lives next to the built files; resolve from this script's
// location so the check works regardless of the caller's working directory.
const jsDir = nodePath.resolve(import.meta.dirname, '..', 'js');
const dssePath = nodePath.join(jsDir, 'memories-manifest.dsse.json');

// The entire trust input: TUF targets payload plus its signature.
const envelope: Envelope = Envelope.fromJSON(JSON.parse(nodeFs.readFileSync(dssePath, 'utf8')));

let failures = 0;

if (envelope.payloadType !== 'application/vnd.tuf+json') {
  console.error(`[ERROR] unexpected DSSE payloadType: ${envelope.payloadType}`);
  process.exit(1);
}
if (!Array.isArray(envelope.signatures) || envelope.signatures.length !== 1 || envelope.signatures[0].sig.length === 0) {
  console.error('[ERROR] DSSE envelope must carry exactly one signature');
  process.exit(1);
}
const payload = Buffer.from(envelope.payload);

// Layer 1: the signature covers the DSSE pre-auth encoding of the payload,
// so any edit — even a self-consistent one — invalidates it.
const sigOk = nodeCrypto.verify(
  null,
  dsse.preAuthEncoding(envelope.payloadType, payload),
  importPublicKey(PUBKEY),
  envelope.signatures[0].sig,
);
if (sigOk) {
  console.log(`[INFO] signature verified (key ${PUBKEY})`);
} else {
  console.error(`[ERROR] signature verification failed (key ${PUBKEY})`);
  failures++;
}

// The payload is the TUF targets object; parse it with tuf-js (validates shape).
let targets: Targets;
try {
  targets = Targets.fromJSON(JSON.parse(payload.toString('utf8')));
} catch (e) {
  console.error(`[ERROR] invalid TUF targets payload: ${(e as Error).message}`);
  process.exit(1);
}

// Layer 2: the targets must carry a valid, unexpired timestamp.
const expiresMs = Date.parse(targets.expires);
if (Number.isNaN(expiresMs)) {
  console.error(`[ERROR] invalid TUF targets expiry: ${targets.expires}`);
  failures++;
} else if (expiresMs <= Date.now()) {
  console.error(`[ERROR] TUF targets expired at ${targets.expires}`);
  failures++;
} else {
  console.log(`[INFO] targets v${targets.version} expires ${targets.expires}`);
}

// Layer 3: every target must name a file on disk whose bytes match
// the listed length and hash. Target keys are bare filenames; the
// ?v=<hash> query in custom.href is URL-only (webpack strips it on disk).
for (const [basename, target] of Object.entries(targets.targets)) {
  if (!target.hashes?.sha256) {
    console.error(`[ERROR] ${basename}: empty hash in targets`);
    failures++;
    continue;
  }
  const file = nodePath.join(jsDir, basename);
  if (!nodeFs.existsSync(file)) {
    console.error(`[ERROR] ${basename}: file missing on disk`);
    failures++;
    continue;
  }
  if (nodeFs.statSync(file).size !== target.length) {
    console.error(`[ERROR] ${basename}: targets length ${target.length} != disk ${nodeFs.statSync(file).size}`);
    failures++;
    continue;
  }
  const actual = sha256File(file);
  if (actual !== target.hashes.sha256) {
    console.error(`[ERROR] ${basename}: targets ${target.hashes.sha256} != sha256 ${actual}`);
    failures++;
    continue;
  }
  console.log(`[INFO] ${basename} sha256:${actual}`);
}

// Every required entry must be present in the targets.
for (const required of REQUIRED_ENTRIES) {
  if (!(required in targets.targets)) {
    console.error(`[ERROR] ${required}: required entry missing from targets`);
    failures++;
  }
}

// Reverse direction: every emitted file must be listed. Catches chunks
// the manifest generation silently dropped.
const emitted = nodeFs.readdirSync(jsDir).filter((f) => f.endsWith('.js'));
for (const file of emitted) {
  if (!(file in targets.targets)) {
    console.error(`[ERROR] ${file}: emitted file missing from targets`);
    failures++;
  }
}

process.exit(failures ? 1 : 0);
