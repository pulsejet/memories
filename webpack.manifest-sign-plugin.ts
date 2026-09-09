import * as nodeCrypto from 'crypto';

// Ed25519 seed signing the webpack manifest.
// Public key (base64, pin for verification): MIJxGvOr0LMg9Isyfi5S4cHQsP+v4mFzrsmY2AYKOjs=
const SEED: Buffer = Buffer.from('BfC97bs84avvLGpUfd0SkOuugTifNffExQ1IS88z58s=', 'base64');

function privateKey(): any {
  return nodeCrypto.createPrivateKey({
    key: Buffer.concat([Buffer.from('302e020100300506032b657004220420', 'hex'), SEED]),
    format: 'der',
    type: 'pkcs8',
  });
}

function sign(bytes: Buffer): string {
  return nodeCrypto.sign(null, bytes, privateKey()).toString('base64');
}

export class ManifestSignPlugin {
  private manifestFile: string;
  private sigFile: string;

  constructor(manifestFile: string, sigFile: string) {
    this.manifestFile = manifestFile;
    this.sigFile = sigFile;
  }

  apply(compiler: any): void {
    compiler.hooks.thisCompilation.tap('ManifestSignPlugin', (compilation: any) => {
      // afterProcessAssets: WebpackManifestPlugin emits at processAssets stage
      // Infinity, so the manifest is only guaranteed to exist here.
      compilation.hooks.afterProcessAssets.tap('ManifestSignPlugin', () => {
        const asset = compilation.getAsset(this.manifestFile);
        if (!asset) return;
        const src = asset.source.source();
        const bytes = Buffer.isBuffer(src) ? src : Buffer.from(src as string, 'utf8');
        compilation.emitAsset(
          this.sigFile,
          new compiler.webpack.sources.RawSource(JSON.stringify({ curve25519: sign(bytes) }, null, 2)),
        );
      });
    });
  }
}
