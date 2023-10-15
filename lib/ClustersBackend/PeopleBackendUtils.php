<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2023 Varun Patil <radialapps@gmail.com>
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

namespace OCA\Memories\ClustersBackend;

use OCP\Files\SimpleFS\ISimpleFile;

trait PeopleBackendUtils
{
    /**
     * Sort a list of faces by the score.
     *
     * Needs the following fields:
     *  - x: x position of the face in the image (percentage)
     *  - y: y position of the face in the image (percentage)
     *  - width: width of the face in the image (percentage)
     *  - height: height of the face in the image (percentage)
     *  - image_width: width of the image in pixels
     *  - image_height: height of the image in pixels
     *  - fileid: file id of the image (unused, converted to int)
     *
     * A score is calculated for each face, and the list is sorted by that score.
     */
    private function sortByScores(array &$list): void
    {
        // Score the face detections
        foreach ($list as &$p) {
            // Make sure we have integers
            $p['fileid'] = (int) $p['fileid'];

            // Get actual pixel size of face
            $iw = min((int) ($p['image_width'] ?: 512), 2048);
            $ih = min((int) ($p['image_height'] ?: 512), 2048);
            $w = (float) $p['width'];
            $h = (float) $p['height'];

            // Get center of face
            $x = (float) $p['x'] + (float) $p['width'] / 2;
            $y = (float) $p['y'] + (float) $p['height'] / 2;

            // 3D normal distribution - if the face is closer to the center, it's better
            $positionScore = exp(-($x - 0.5) ** 2 * 4) * exp(-($y - 0.5) ** 2 * 4);

            // Root size distribution - if the image is bigger, it's better,
            // but it doesn't matter beyond a certain point
            $imgSizeScore = ($iw * 100) ** (1 / 2) * ($ih * 100) ** (1 / 2);

            // Faces occupying too much of the image don't look particularly good
            $faceSizeScore = (-$w ** 2 + $w) * (-$h ** 2 + $h);

            // Combine scores
            $p['score'] = $positionScore * $imgSizeScore * $faceSizeScore;
        }

        // Sort previews by score descending
        usort($list, static fn ($a, $b) => $b['score'] <=> $a['score']);
    }

    /**
     * Crop the preview to the face.
     *
     * Needs the following fields:
     * - x: x position of the face in the image (percentage)
     * - y: y position of the face in the image (percentage)
     * - width: width of the face in the image (percentage)
     * - height: height of the face in the image (percentage)
     *
     * @param ISimpleFile $file    Actual file containing the image
     * @param array       $photo   The face object
     * @param float       $padding The padding to add around the face
     *
     * @return string[] [Blob, mimetype] of resulting image
     *
     * @throws \Exception if file could not be used
     *
     * @psalm-return list{string, string}
     */
    private function cropFace(ISimpleFile $file, array $photo, float $padding): array
    {
        $img = new \OCP\Image();
        $img->loadFromData($file->getContent());

        $iw = $img->width();
        $ih = $img->height();

        if ($iw <= 0 || $ih <= 0) {
            throw new \Exception('Invalid image size');
        }

        // Get target dimensions
        $dw = (float) $photo['width'];
        $dh = (float) $photo['height'];
        $dcx = (float) $photo['x'] + (float) $photo['width'] / 2;
        $dcy = (float) $photo['y'] + (float) $photo['height'] / 2;
        $faceDim = max($dw * $iw, $dh * $ih) * $padding;

        // Crop image
        $img->crop(
            (int) ($dcx * $iw - $faceDim / 2),
            (int) ($dcy * $ih - $faceDim / 2),
            (int) $faceDim,
            (int) $faceDim,
        );

        // Max 512x512
        $img->scaleDownToFit(512, 512);

        // Get blob and mimetype
        $data = $img->data() ?: throw new \Exception('Could not get image data');
        $mime = $img->mimeType() ?: throw new \Exception('Could not get image mimetype');

        return [$data, $mime];
    }
}
