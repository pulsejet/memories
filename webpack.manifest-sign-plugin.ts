import * as nodeCrypto from 'crypto';
import { dsse } from '@sigstore/core';
import { Envelope } from '@sigstore/protobuf-specs';
import { canonicalize } from '@tufjs/canonical-json';
import { TargetFile, Targets } from '@tufjs/models';

// Default Ed25519 seed signing the webpack manifest.
// Set MANIFEST_SIGNING_KEY to a base64 seed to override it.
// Default public key (base64, pin for verification): MIJxGvOr0LMg9Isyfi5S4cHQsP+v4mFzrsmY2AYKOjs=
const DEFAULT_SEED = 'BfC97bs84avvLGpUfd0SkOuugTifNffExQ1IS88z58s=';

// DSSE envelope payload type for TUF metadata.
const TUF_DSSE_PAYLOAD_TYPE = 'application/vnd.tuf+json';

function signingSeed(): Buffer {
  return Buffer.from(process.env.MANIFEST_SIGNING_KEY || DEFAULT_SEED, 'base64');
}

function privateKey(): any {
  // ASN.1 DER header for an Ed25519 PKCS#8 private key (algo OID 1.3.101.112);
  // Node cannot import a raw 32-byte seed, so prefix it to form a valid DER blob.
  const header = Buffer.from('302e020100300506032b657004220420', 'hex');
  return nodeCrypto.createPrivateKey({
    key: Buffer.concat([header, signingSeed()]),
    format: 'der',
    type: 'pkcs8',
  });
}

export function manifestPublicKey(): string {
  const der: Buffer = nodeCrypto.createPublicKey(privateKey()).export({ format: 'der', type: 'spki' });
  return der.subarray(-32).toString('base64');
}

export function manifestKeyID(): string {
  const key = {
    keytype: 'ed25519',
    scheme: 'ed25519',
    keyval: {
      public: Buffer.from(manifestPublicKey(), 'base64').toString('hex'),
    },
  };
  return nodeCrypto.createHash('sha256').update(canonicalize(key), 'utf8').digest('hex');
}

export class ManifestPlugin {
  private manifestFile: string;

  constructor(manifestFile: string) {
    this.manifestFile = manifestFile;
  }

  apply(compiler: any): void {
    compiler.hooks.thisCompilation.tap('ManifestPlugin', (compilation: any) => {
      // Must stay registered before ManifestSignPlugin (tap order).
      compilation.hooks.afterProcessAssets.tap('ManifestPlugin', () => {
        // Resolve public path prefix for hrefs.
        let publicPath: string = compilation.outputOptions.publicPath ?? '';
        if (publicPath && !publicPath.endsWith('/')) publicPath += '/';

        // Compute an expiry date for the TUF targets (3 years from now).
        const expiryDate = new Date();
        expiryDate.setUTCFullYear(expiryDate.getUTCFullYear() + 3);
        const expiry = expiryDate.toISOString().replace(/\.\d{3}Z$/, 'Z');

        // TUF targets for every emitted JS file, hashed by content.
        // Versioned by build time so newer builds supersede older ones.
        const targets = new Targets({
          version: Math.floor(Date.now() / 1000),
          specVersion: '1.0.0',
          expires: expiry,
          targets: {},
        });

        for (const { name, source } of compilation.getAssets()) {
          const m = /^(.*\.js)(\?.*)?$/.exec(name);
          if (!m) continue;

          // Hash the emitted source bytes directly.
          const src = source.source();
          const bytes = Buffer.isBuffer(src) ? src : Buffer.from(src as string, 'utf8');
          const hash = nodeCrypto.createHash('sha256').update(bytes).digest('hex');

          targets.addTarget(
            new TargetFile({
              path: m[1],
              length: bytes.length,
              hashes: {
                sha256: hash,
              },
              unrecognizedFields: {
                custom: {
                  href: `${publicPath}${m[1]}?v=${hash}`,
                },
              },
            }),
          );
        }

        // Emit the bare TUF targets (the `signed` portion, no signatures).
        compilation.emitAsset(
          this.manifestFile,
          new compiler.webpack.sources.RawSource(JSON.stringify(targets.toJSON(), null, 2)),
        );
      });
    });
  }
}

export class ManifestSignPlugin {
  private manifestFile: string;
  private dsseFile: string;

  constructor(manifestFile: string, dsseFile: string) {
    this.manifestFile = manifestFile;
    this.dsseFile = dsseFile;
  }

  apply(compiler: any): void {
    console.info('Manifest signing public key:', manifestPublicKey());
    console.info('Manifest signing keyid:', manifestKeyID());
    compiler.hooks.thisCompilation.tap('ManifestSignPlugin', (compilation: any) => {
      compilation.hooks.afterProcessAssets.tap('ManifestSignPlugin', () => {
        // Read the emitted bare TUF targets.
        const asset = compilation.getAsset(this.manifestFile);
        if (!asset) return;

        const src = asset.source.source();
        const bytes = Buffer.isBuffer(src) ? src : Buffer.from(src as string, 'utf8');
        const targets = Targets.fromJSON(JSON.parse(bytes.toString('utf8')));

        // Sign the DSSE pre-auth encoding of the canonical targets.
        const keyId = manifestKeyID();
        const canonical = Buffer.from(canonicalize(targets.toJSON()), 'utf8');
        const rawSig = nodeCrypto.sign(
          null,
          dsse.preAuthEncoding(TUF_DSSE_PAYLOAD_TYPE, canonical),
          privateKey(),
        );

        // Emit the signature as a DSSE envelope (sigstore type);
        // the manifest itself stays unsigned.
        const envelope: Envelope = {
          payload: canonical,
          payloadType: TUF_DSSE_PAYLOAD_TYPE,
          signatures: [{ keyid: keyId, sig: rawSig }],
        };

        const { RawSource } = compiler.webpack.sources;
        compilation.emitAsset(this.dsseFile, new RawSource(JSON.stringify(Envelope.toJSON(envelope), null, 2)));
      });
    });
  }
}
