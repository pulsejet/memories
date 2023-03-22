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

namespace OCA\Memories\Controller;

trait PeopleControllerUtils
{
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
        usort($list, fn ($a, $b) => $b['score'] <=> $a['score']);
    }

    /**
     * Crop the preview to the face.
     *
     * @param \OCP\Files\SimpleFS\ISimpleFile $file
     * @param array                           $object The face object
     *
     * @return [Blob, mimetype] of resulting image
     *
     * @throws \Exception if file could not be used
     */
    private function cropFace($file, array $photo, float $padding)
    {
        /** @var \Imagick */
        $image = null;

        try {
            $image = new \Imagick();
            $image->readImageBlob($file->getContent());
        } catch (\ImagickException $e) {
            throw new \Exception('Could not read image');
        }

        $iw = $image->getImageWidth();
        $ih = $image->getImageHeight();

        if ($iw <= 0 || $ih <= 0) {
            $image = null;

            throw new \Exception('Invalid image size');
        }

        // Set quality and make progressive
        $image->setImageCompressionQuality(80);
        $image->setInterlaceScheme(\Imagick::INTERLACE_PLANE);

        // Crop image
        $dw = (float) $photo['width'];
        $dh = (float) $photo['height'];
        $dcx = (float) $photo['x'] + (float) $photo['width'] / 2;
        $dcy = (float) $photo['y'] + (float) $photo['height'] / 2;
        $faceDim = max($dw * $iw, $dh * $ih) * $padding;
        $image->cropImage(
            (int) $faceDim,
            (int) $faceDim,
            (int) ($dcx * $iw - $faceDim / 2),
            (int) ($dcy * $ih - $faceDim / 2),
        );
        $image->scaleImage(512, 512, true);

        return [$image->getImageBlob(), $image->getImageMimeType()];
    }
}
