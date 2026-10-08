#!/usr/bin/env -S npx tsx
/**
 * Verifies the build manifest after `npm run build`.
 *
 * Two layers, mirroring what the native clients enforce:
 *   1. Content transparency: sha256 of every listed file must match its
 *      manifest hash. Catches missing hashes (e.g. an unhashed service
 *      worker), stale files, and truncated downloads.
 *   2. Authenticity: the Ed25519 sidecar signature must verify over the
 *      raw manifest bytes against a pinned key. Catches manifest tampering
 *      even when hashes are self-consistent.
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

// The manifest lives next to the built files; resolve from this script's location
// so the check works regardless of the caller's working directory.
const jsDir = nodePath.resolve(import.meta.dirname, '..', 'js');
const manifestPath = nodePath.join(jsDir, 'memories-manifest.json');
const sigPath = nodePath.join(jsDir, 'memories-manifest.sig.json');

// Typings for manifest objects.
type Manifest = Record<string, { hash: string; href: string }>;
type Signature = { curve25519: string };

// Raw manifest bytes (signature input; must stay byte-identical to the signed file).
const manifestBytes = nodeFs.readFileSync(manifestPath);
// Parsed manifest: basename -> { hash, href } for every emitted file.
const manifest = JSON.parse(manifestBytes.toString('utf8')) as Manifest;
// Parsed sidecar signature document.
const sig = JSON.parse(nodeFs.readFileSync(sigPath, 'utf8')) as Signature;

// Layer 1: every entry must name a file on disk whose bytes hash to the listed hash.
// Manifest keys are bare filenames; the ?v=<hash> query in hrefs is URL-only
// (webpack strips it when writing files to disk).
let failures = 0;
for (const [basename, entry] of Object.entries(manifest)) {
  if (!entry.hash) {
    console.error(`[ERROR] ${basename}: empty hash in manifest`);
    failures++;
    continue;
  }
  const file = nodePath.join(jsDir, basename);
  if (!nodeFs.existsSync(file)) {
    console.error(`[ERROR] ${basename}: file missing on disk`);
    failures++;
    continue;
  }
  const actual = sha256File(file);
  if (actual !== entry.hash) {
    console.error(`[ERROR] ${basename}: manifest ${entry.hash} != sha256 ${actual}`);
    failures++;
    continue;
  }
  console.log(`[INFO] ${basename} sha256:${actual}`);
}

// Layer 2: the signature covers the manifest bytes verbatim, so any edit —
// even a self-consistent one — invalidates it.
const sigBuf = Buffer.from(sig.curve25519, 'base64');
const sigPubKey = importPublicKey(PUBKEY);
const sigOk = nodeCrypto.verify(null, manifestBytes, sigPubKey, sigBuf);
if (sigOk) {
  console.log(`[INFO] signature verified (key ${PUBKEY})`);
} else {
  console.error(`[ERROR] signature verification failed (key ${PUBKEY})`);
  failures++;
}

// Every required entry must be present in the manifest.
for (const required of REQUIRED_ENTRIES) {
  if (!(required in manifest)) {
    console.error(`[ERROR] ${required}: required entry missing from manifest`);
    failures++;
  }
}

// Reverse direction: every emitted file must be listed. Catches chunks
// the manifest generation silently dropped.
const emitted = nodeFs.readdirSync(jsDir).filter((f) => f.endsWith('.js'));
for (const file of emitted) {
  if (!(file in manifest)) {
    console.error(`[ERROR] ${file}: emitted file missing from manifest`);
    failures++;
  }
}

process.exit(failures ? 1 : 0);
