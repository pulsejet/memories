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

use OC\Preview\Db\Preview;
use OC\Preview\Db\PreviewMapper;
use OC\Preview\Storage\StorageFactory;
use OCP\Files\File;
use OCP\Files\SimpleFS\ISimpleFile;
use Psr\Log\LoggerInterface;

/**
 * Replaces non-vips preview cache contents with vips bytes on serve.
 *
 * Only max rows are skipped. The trailer check seeks the
 * last bytes only, so cached bytes are never fully read here.
 */
final class VipsPatcher
{
    public function __construct(
        private Vips $vips,
        private PreviewMapper $previewMapper,
        private StorageFactory $storageFactory,
        private LoggerInterface $logger,
    ) {}

    /**
     * Replace a non-vips cache row with bytes downscaled from the max.
     *
     * Only max rows are skipped (nothing to derive them from).
     *
     * @param null|array<\OC\Preview\Db\Preview> $previews rows for this file, fetched when null
     *
     * @return bool true when replaced and the caller must re-read the preview
     */
    public function patch(File $file, ISimpleFile $preview, ?array $previews = null): bool
    {
        if (!$this->vips->isAvailable($file)) {
            return false;
        }

        try {
            if (VipsTrailer::fileHas($preview)) {
                return false;
            }

            [$row, $max] = $this->findRows($file, $preview, $previews);
            if (null === $row || null === $max || $row->isMax()) {
                return false;
            }

            // Vips always emits JPEG; never overwrite rows of another type
            if ('image/jpeg' !== $row->getMimeType() || 'image/jpeg' !== $max->getMimeType()) {
                return false;
            }

            return $this->replaceRow($row, $max);
        } catch (\Throwable $e) {
            $this->logger->debug('vips patch failed: '.$e->getMessage(), ['app' => 'memories']);

            return false;
        }
    }

    /**
     * Downscale $row from $max and overwrite it in place.
     */
    private function replaceRow(Preview $row, Preview $max): bool
    {
        try {
            $in = $this->storageFactory->readPreview($max);
        } catch (\Throwable) {
            return false;
        }

        try {
            $blob = stream_get_contents($in);
        } finally {
            /** @psalm-suppress RedundantConditionGivenDocblockType */
            if (\is_resource($in)) {
                fclose($in);
            }
        }
        if (!\is_string($blob) || '' === $blob) {
            return false;
        }

        $img = $this->vips->getThumbnailFromBlob(
            $blob,
            $row->getWidth(),
            $row->getHeight(),
            $row->isCropped() ? 'attention' : 'none',
        );
        if (!$img->valid()) {
            return false;
        }

        $stream = $img->resource();
        if (!\is_resource($stream)) {
            return false;
        }

        try {
            $size = $this->storageFactory->writePreview($row, $stream);
        } finally {
            /** @psalm-suppress RedundantConditionGivenDocblockType */
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }
        if (!$size) {
            return false;
        }

        $row->setSize($size);
        $row->setMtime(time());

        try {
            $this->previewMapper->update($row);
        } catch (\Throwable $e) {
            $this->logger->debug('vips patch: db update failed: '.$e->getMessage(), ['app' => 'memories']);
        }

        return true;
    }

    /**
     * Find the cache row behind $preview plus the max row, fetching when needed.
     *
     * @param null|array<\OC\Preview\Db\Preview> $previews
     *
     * @return array{null|\OC\Preview\Db\Preview, null|\OC\Preview\Db\Preview}
     */
    private function findRows(File $file, ISimpleFile $preview, ?array $previews): array
    {
        if (null === $previews) {
            try {
                $map = $this->previewMapper->getAvailablePreviews([$file->getId()]);
            } catch (\Throwable) {
                return [null, null];
            }
            $previews = $map[$file->getId()] ?? [];
        }

        $row = null;
        foreach ($previews as $p) {
            if ($p->getName() === $preview->getName()) {
                $row = $p;

                break;
            }
        }
        if (null === $row) {
            return [null, null];
        }

        $max = null;
        foreach ($previews as $p) {
            if ($p->isMax() && $p->getVersion() === $row->getVersion()) {
                $max = $p;

                break;
            }
        }

        return [$row, $max];
    }
}
