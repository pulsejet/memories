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
use OCP\Files\SimpleFS\ISimpleFile;

/**
 * Marker appended to vips-generated preview bytes (after the JPEG EOI,
 * which decoders ignore). Lets us tell vips bytes apart from core GD
 * bytes already sitting in the preview cache.
 */
final class VipsTrailer
{
    /** Fixed length, padded for version string growth. */
    public const TRAILER_LEN = 64;

    /** Prefix identifying vips bytes; the rest is the padded binary version. */
    private const TRAILER_PREFIX = "\nmemories-vips-";

    /** Build the padded marker appended after the JPEG EOI. */
    public static function build(): string
    {
        return str_pad(self::TRAILER_PREFIX.BinExt::VIPS_VER."\n", self::TRAILER_LEN, "\0");
    }

    /** Version-tolerant: old binaries still count as vips. */
    public static function has(string $blob): bool
    {
        if (\strlen($blob) < self::TRAILER_LEN) {
            return false;
        }

        return str_starts_with(substr($blob, -self::TRAILER_LEN), self::TRAILER_PREFIX);
    }

    /** Tail-probe the file, falling back to the full blob when unseekable. */
    public static function fileHas(ISimpleFile $preview): bool
    {
        $stream = $preview->read();
        if (\is_resource($stream)) {
            if (0 === @fseek($stream, -self::TRAILER_LEN, SEEK_END)) {
                $tail = fread($stream, self::TRAILER_LEN);
                fclose($stream);
                if (\is_string($tail) && self::TRAILER_LEN === \strlen($tail)) {
                    return self::has($tail);
                }
            } else {
                fclose($stream);
            }
        }

        return self::has($preview->getContent());
    }
}
