import * as nodeCrypto from 'crypto';

// Default Ed25519 seed signing the webpack manifest.
// Set MANIFEST_SIGNING_KEY to a base64 seed to override it.
// Default public key (base64, pin for verification): MIJxGvOr0LMg9Isyfi5S4cHQsP+v4mFzrsmY2AYKOjs=
const DEFAULT_SEED = 'BfC97bs84avvLGpUfd0SkOuugTifNffExQ1IS88z58s=';

function signingSeed(): Buffer {
  return Buffer.from(process.env.MANIFEST_SIGNING_KEY || DEFAULT_SEED, 'base64');
}

function privateKey(): any {
  // ASN.1 DER header for an Ed25519 PKCS#8 private key (algo OID 1.3.101.112);
  // Node cannot import a raw 32-byte seed, so prefix it to form a valid DER blob.
  const header = Buffer.from('302e020100300506032b657004220420', 'hex');
  return nodeCrypto.createPrivateKey({ key: Buffer.concat([header, signingSeed()]), format: 'der', type: 'pkcs8' });
}

export function manifestPublicKey(): string {
  const der: Buffer = nodeCrypto.createPublicKey(privateKey()).export({ format: 'der', type: 'spki' });
  return der.subarray(-32).toString('base64');
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

        // Hash every emitted JS file by content, including the Workbox
        // service worker (fixed filename, no ?v=[contenthash]).
        const manifest: Record<string, { hash: string; href: string }> = {};
        for (const { name, source } of compilation.getAssets()) {
          const m = /^(.*\.js)(\?.*)?$/.exec(name);
          if (!m) continue;

          // Hash the emitted source bytes directly.
          const src = source.source();
          const bytes = Buffer.isBuffer(src) ? src : Buffer.from(src as string, 'utf8');
          const hash = nodeCrypto.createHash('sha256').update(bytes).digest('hex');

          // Guard against webpack filenames drifting from content hashes.
          if (m[2] && m[2] !== `?v=${hash}`) {
            compilation.errors.push(new Error(`Manifest: content hash mismatch for ${name}`));
            return;
          }

          manifest[m[1]] = { hash, href: `${publicPath}${m[1]}?v=${hash}` };
        }

        // Emit the manifest for signing.
        compilation.emitAsset(
          this.manifestFile,
          new compiler.webpack.sources.RawSource(JSON.stringify(manifest, null, 2)),
        );
      });
    });
  }
}

export class ManifestSignPlugin {
  private manifestFile: string;
  private sigFile: string;

  constructor(manifestFile: string, sigFile: string) {
    this.manifestFile = manifestFile;
    this.sigFile = sigFile;
  }

  apply(compiler: any): void {
    console.info('Manifest signing public key:', manifestPublicKey());
    compiler.hooks.thisCompilation.tap('ManifestSignPlugin', (compilation: any) => {
      // afterProcessAssets: ManifestPlugin emits at afterProcessAssets too
      // but is registered first, so the manifest exists here.
      compilation.hooks.afterProcessAssets.tap('ManifestSignPlugin', () => {
        // Read the emitted manifest.
        const asset = compilation.getAsset(this.manifestFile);
        if (!asset) return;
        const src = asset.source.source();
        const bytes = Buffer.isBuffer(src) ? src : Buffer.from(src as string, 'utf8');

        // Sign the manifest bytes.
        const key = privateKey();
        const sig: Buffer = nodeCrypto.sign(null, bytes, key);

        // Emit the signature.
        compilation.emitAsset(
          this.sigFile,
          new compiler.webpack.sources.RawSource(JSON.stringify({ curve25519: sig.toString('base64') }, null, 2)),
        );
      });
    });
  }
}
