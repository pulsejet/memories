<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Varun Patil <radialapps@gmail.com>
 * @author Varun Patil <radialapps@gmail.com>
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\Memories\Preview;

use OC\StreamImage;
use OCA\Memories\Service\BinExt;
use OCA\Memories\Settings\SystemConfig;
use OCA\Memories\Util;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IImage;
use OCP\Preview\IProviderV2;
use Psr\Log\LoggerInterface;

/**
 * Preview provider backed by the static vips binary.
 *
 * The binary needs no system imaging libraries, unlike the Imagick-backed
 * core providers. One class serves all formats below; it is registered with
 * a single combined regex in Application::register().
 */
final class Vips implements IProviderV2
{
    /** MIME types thumbnailable by the static build. */
    public const MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/avif',
        'image/heic',
        'image/heif',
        'image/x-heic',
        'image/x-heif',
        'image/tiff',
    ];

    /**
     * Registration regex covering all MIME_TYPES.
     *
     * Deliberately long: the preview manager tries providers in descending
     * order of regex length, so this sorts before the core single-format
     * keys and vips takes priority over the core providers.
     */
    public const MIME_REGEX = '/image\/(jpeg|png|gif|webp|avif|(x-)?hei[cf]|tiff)/';

    public function __construct(
        private BinExt $binExt,
        private SystemConfig $config,
        private IConfig $sysConfig,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) $this->config->get('memories.vips.enabled')
            && (bool) $this->config->get('memories.vips.path');
    }

    #[\Override]
    public function getMimeType(): string
    {
        return self::MIME_REGEX;
    }

    #[\Override]
    public function isAvailable(FileInfo $file): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        if ($file->getSize() <= 0 || $file->isEncrypted()) {
            return false;
        }

        $maxSize = $this->sysConfig->getSystemValueInt('preview_max_filesize_image', 50);
        if (-1 !== $maxSize && $file->getSize() > $maxSize * 1024 * 1024) {
            return false;
        }

        return true;
    }

    #[\Override]
    public function getThumbnail(File $file, int $maxX, int $maxY): ?IImage
    {
        if (!$this->isAvailable($file)) {
            return null;
        }

        try {
            $path = $file->getStorage()->getLocalFile($file->getInternalPath());
            if (!\is_string($path) || '' === $path) {
                throw new \Exception('failed to get local file for '.$file->getPath());
            }

            return $this->getThumbnailFromPath($path, $maxX, $maxY);
        } catch (\Throwable $e) {
            $this->logger->warning('vips preview failed: '.$e->getMessage(), ['app' => 'memories']);
        }

        return null;
    }

    /**
     * Thumbnail an image at a local path. The only vips entry point.
     * Throws on any failure; callers catch.
     */
    public function getThumbnailFromPath(string $inputPath, int $width, int $height): IImage
    {
        $vips = $this->binExt->getVipsBin();

        // Same key as the core providers (canonically an int)
        $quality = min(100, max(10, $this->appConfig->getValueInt('preview', 'jpeg_quality', 80)));

        // JSON on stdin; OUTPUT picks the format, stdout:true streams the bytes.
        $stdin = json_encode([
            'cmd' => 'thumbnail',
            'input' => $inputPath,
            'output' => 'output.jpg',
            'stdout' => true,
            'width' => max(1, $width),
            'height' => max(1, $height),
            'crop' => 'none',
            'quality' => $quality,
        ]);
        if (!\is_string($stdin)) {
            throw new \Exception('failed to encode vips request');
        }

        [1 => $data, 2 => $stderr] = Util::execSafe2([$vips], 30000, $stdin, [1, 2]);

        if (!\is_string($data) || empty($data)) {
            throw new \Exception(self::vipsError((string) $stderr) ?: 'empty output from vips');
        }

        // Thumbnail/info JSON is printed on stderr.
        $meta = json_decode(trim((string) $stderr), true);
        $width = (int) ($meta['width'] ?? 0);
        $height = (int) ($meta['height'] ?? 0);
        if ($width <= 0 || $height <= 0) {
            throw new \Exception(self::vipsError((string) $stderr) ?: 'invalid info from vips');
        }

        // Wrap the output in a stream.
        $out = fopen('php://temp', 'w+');
        if (!\is_resource($out)) {
            throw new \Exception('cannot open temp stream');
        }
        fwrite($out, $data);
        rewind($out);

        // StreamImage ensures that the file is written without re-encode,
        // preserving U-HDR and ICC profiles.
        return new StreamImage($out, 'image/jpeg', $width, $height);
    }

    /**
     * Convert image via vips `convert` (full decode, no resize).
     * Output extension picks the saver (e.g. output.jpg).
     * Throws on failure.
     */
    public function convertFormat(string $inputPath, string $output, int $quality = 85): string
    {
        $vips = $this->binExt->getVipsBin();

        $stdin = json_encode([
            'cmd' => 'convert',
            'input' => $inputPath,
            'output' => $output,
            'stdout' => true,
            'quality' => min(100, max(10, $quality)),
        ]);
        if (!\is_string($stdin)) {
            throw new \Exception('failed to encode vips request');
        }

        [1 => $data, 2 => $stderr] = Util::execSafe2([$vips], 30000, $stdin, [1, 2]);

        if (!\is_string($data) || '' === $data) {
            throw new \Exception(self::vipsError((string) $stderr) ?: 'empty output from vips');
        }

        return $data;
    }

    /** Extract the error message from vips stderr, if any. */
    private static function vipsError(string $stderr): ?string
    {
        $stderr = trim($stderr);
        if ('' === $stderr) {
            return null;
        }

        $meta = json_decode($stderr, true);
        if (\is_array($meta) && isset($meta['error']) && \is_string($meta['error']) && '' !== trim($meta['error'])) {
            return trim($meta['error']);
        }

        return $stderr;
    }
}
