<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2022, Varun Patil <radialapps@gmail.com>
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\Memories\Service;

use OCA\Memories\AppInfo\Application;
use OCA\Memories\Settings\SystemConfig;
use OCP\Files\Node;
use OCP\IPreview;

final class MIME
{
    /** @var string[] */
    private ?array $mimeList = null;

    public function __construct(
        private IPreview $preview,
        private SystemConfig $systemConfig,
    ) {}

    /**
     * Get list of MIME types to process.
     */
    public function getMimeList(): array
    {
        return $this->mimeList ??= array_merge(
            $this->getPreviewMimes(Application::IMAGE_MIMES),
            Application::VIDEO_MIMES,
        );
    }

    /**
     * Get list of MIME types that have a preview.
     */
    public function getPreviewMimes(array $source): array
    {
        return array_filter($source, fn ($m) => $this->preview->isMimeSupported($m));
    }

    /**
     * Get list of all supported MIME types.
     */
    public function getAllMimes(): array
    {
        return array_merge(
            Application::IMAGE_MIMES,
            Application::VIDEO_MIMES,
        );
    }

    /**
     * Check if a file is supported.
     *
     * @param Node $file file to check
     */
    public function isSupported(Node $file): bool
    {
        return \in_array($file->getMimeType(), $this->getMimeList(), true);
    }

    /**
     * Check if a file is a video.
     *
     * @param Node $file file to check
     */
    public function isVideo(Node $file): bool
    {
        return \in_array($file->getMimeType(), Application::VIDEO_MIMES, true);
    }

    /**
     * Checks if the specified node's path is allowed to be indexed.
     */
    public function isPathAllowed(string $path): bool
    {
        // Always exclude some predefined patterns
        //   .trashed-<file> (https://github.com/nextcloud/android/issues/10645)
        if (preg_match('/\/.trashed-[^\/]*$/', $path)) {
            return false;
        }

        $blacklist = (string) $this->systemConfig->get('memories.index.path.blacklist');
        $pattern = trim($blacklist);
        if ('' !== $pattern && !\is_int(preg_match("/{$pattern}/", ''))) {
            throw new \Exception('Invalid regex pattern in memories.index.path.blacklist');
        }

        return '' === $pattern || !preg_match("/{$pattern}/", $path);
    }
}
