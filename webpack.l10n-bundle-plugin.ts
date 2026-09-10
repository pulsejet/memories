import * as fs from 'fs';
import * as nodeCrypto from 'crypto';
import * as path from 'path';

/**
 * Webpack plugin that bundles every `l10n/*.json` locale file into a
 * single JS asset exposing them on the global scope.
 *
 * The emitted asset contains one statement:
 *
 *   `globalThis.__packed_l10n = { "<lang>": <parsed JSON of <lang>.json>, ... };`
 *
 * It is emitted at the `PROCESS_ASSETS_STAGE_ADDITIONAL` stage so that
 * downstream plugins running later (e.g. `WebpackManifestPlugin`, which
 * runs at `Infinity`) pick it up and include it in the JS manifest and
 * therefore in the manifest signature and service-worker precache list.
 */
export class L10nBundlePlugin {
  /** App name used as filename prefix (e.g. `memories` -> `memories-l10n.js`). */
  private appName: string;

  /** Absolute path of the directory containing the `<lang>.json` locale files. */
  private l10nDir: string;

  /**
   * Create the plugin.
   *
   * @param appName - App name prefix for the emitted asset filename.
   * @param l10nDir - Absolute path to the `l10n` directory. Passed in from
   *   the webpack config because this file is loaded as ESM, where
   *   `__dirname` is not available.
   */
  constructor(appName: string, l10nDir: string) {
    this.appName = appName;
    this.l10nDir = l10nDir;
  }

  /**
   * Register the plugin with the webpack compiler.
   *
   * @param compiler - Webpack compiler instance (typed as `any` to stay
   *   compatible across webpack 5 minor versions without extra deps).
   */
  apply(compiler: any): void {
    compiler.hooks.thisCompilation.tap('L10nBundlePlugin', (compilation: any) => {
      compilation.hooks.processAssets.tap(
        {
          name: 'L10nBundlePlugin',
          // Must run before WebpackManifestPlugin (stage Infinity) so the
          // bundle shows up in the manifest.
          stage: compiler.webpack.Compilation.PROCESS_ASSETS_STAGE_ADDITIONAL,
        },
        () => {
          // Collect every locale: filename `<lang>.json` -> language key `<lang>`.
          // Sorted for deterministic, reproducible builds.
          const map: Record<string, any> = {};
          for (const f of fs.readdirSync(this.l10nDir).sort()) {
            if (!f.endsWith('.json')) continue;
            const lang = f.slice(0, -'.json'.length);
            try {
              map[lang] = JSON.parse(fs.readFileSync(path.join(this.l10nDir, f), 'utf8'));
            } catch {
              // Skip unreadable or invalid locale files; one broken
              // translation must not fail the whole build.
            }
          }

          // Single-statement bundle: assign the whole language map globally.
          // JSON.stringify output is always valid JS, so no extra escaping needed.
          const code = `globalThis.__packed_l10n=${JSON.stringify(map)};`;

          // Content hash for cache busting, mirroring output options
          // (`hashFunction: sha256`, `hashDigestLength: 64`): the `?v=<hash>`
          // suffix is what WebpackManifestPlugin splits off as the entry hash.
          const hash = nodeCrypto.createHash('sha256').update(code).digest('hex').slice(0, 64);

          compilation.emitAsset(
            `${this.appName}-l10n.js?v=${hash}`,
            new compiler.webpack.sources.RawSource(code),
          );
        },
      );
    });
  }
}
