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

use OCA\Memories\Service\BinExt;
use OCA\Memories\Util;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IImage;
use OCP\Image;
use OCP\Preview\IProviderV2;
use Psr\Log\LoggerInterface;

/**
 * Preview provider backed by the static vips binary in bin-ext/.
 *
 * The binary needs no system imaging libraries, unlike the Imagick-backed
 * core providers. One class serves all formats below; it is registered with
 * a single combined regex in Application::register().
 */
final class Vips implements IProviderV2
{
    /** MIME types thumbnailable by the static build (verified, see get-bin-ext.sh). */
    public const MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/avif',
        'image/heic',
        'image/heif',
        'image/tiff',
        'image/jp2',
        'image/jxl',
    ];

    /**
     * Registration regex covering all MIME_TYPES.
     *
     * Deliberately long: the preview manager tries providers in descending
     * order of regex length, so this sorts before the core single-format
     * keys and vips takes priority over the core providers.
     */
    public const MIME_REGEX = '/image\/(jpeg|png|gif|webp|avif|hei[cf]|tiff|jp2|jxl)/';

    public function __construct(
        private BinExt $binExt,
        private IConfig $config,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {}

    #[\Override]
    public function getMimeType(): string
    {
        return self::MIME_REGEX;
    }

    #[\Override]
    public function isAvailable(FileInfo $file): bool
    {
        if (!$this->config->getSystemValueBool('memories.vips.enabled', true)) {
            return false;
        }

        if ($file->getSize() <= 0) {
            return false;
        }

        try {
            $this->binExt->getVipsBin();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    #[\Override]
    public function getThumbnail(File $file, int $maxX, int $maxY): ?IImage
    {
        if (!$this->isAvailable($file)) {
            return null;
        }

        // Respect the global cap for source image size
        $maxSize = $this->config->getSystemValueInt('preview_max_filesize_image', 50);
        if (-1 !== $maxSize && $file->getSize() > $maxSize * 1024 * 1024) {
            return null;
        }

        try {
            $vips = $this->binExt->getVipsBin();
        } catch (\Throwable $e) {
            $this->logger->warning('vips preview: '.$e->getMessage(), ['app' => 'memories']);

            return null;
        }

        try {
            $in = $this->getLocalFile($file);

            // Same key as the core providers (canonically an int)
            $quality = min(100, max(10, $this->appConfig->getValueInt('preview', 'jpeg_quality', 80)));

            // JSON on stdin; OUTPUT picks the format, stdout:true streams the bytes.
            $stdin = json_encode([
                'cmd' => 'thumbnail',
                'input' => $in,
                'output' => 'output.webp',
                'stdout' => true,
                'width' => max(1, $maxX),
                'height' => max(1, $maxY),
                'crop' => 'none',
                'quality' => $quality,
            ]);
            if (!\is_string($stdin)) {
                throw new \Exception('failed to encode vips request');
            }

            [$data, $stderr] = Util::execSafe2([$vips], 30000, $stdin, true, true);

            if (!\is_string($data) || '' === $data) {
                throw new \Exception(trim((string) $stderr) ?: 'empty output from vips');
            }

            $image = new Image();
            $image->loadFromData($data);
            if (!$image->valid()) {
                throw new \Exception('invalid image data from vips');
            }

            return $image;
        } catch (\Throwable $e) {
            $this->logger->debug('vips preview failed: '.$e->getMessage(), ['app' => 'memories']);

            return null;
        }
    }

    /** Get a local path for $file. */
    private function getLocalFile(File $file): string
    {
        $path = $file->getStorage()->getLocalFile($file->getInternalPath());
        if (!\is_string($path) || '' === $path) {
            throw new \Exception('failed to get local file for '.$file->getPath());
        }

        return $path;
    }
}
