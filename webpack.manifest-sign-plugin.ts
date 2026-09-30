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
      // afterProcessAssets: WebpackManifestPlugin emits at processAssets stage
      // Infinity, so the manifest is only guaranteed to exist here.
      compilation.hooks.afterProcessAssets.tap('ManifestSignPlugin', () => {
        const asset = compilation.getAsset(this.manifestFile);
        if (!asset) return;
        const src = asset.source.source();
        const bytes = Buffer.isBuffer(src) ? src : Buffer.from(src as string, 'utf8');
        const key = privateKey();
        const sig: Buffer = nodeCrypto.sign(null, bytes, key);
        if (!nodeCrypto.verify(null, bytes, nodeCrypto.createPublicKey(key), sig)) {
          compilation.errors.push(new Error('ManifestSignPlugin: self-verification of manifest signature failed'));
          return;
        }
        compilation.emitAsset(
          this.sigFile,
          new compiler.webpack.sources.RawSource(JSON.stringify({ curve25519: sig.toString('base64') }, null, 2)),
        );
      });
    });
  }
}
