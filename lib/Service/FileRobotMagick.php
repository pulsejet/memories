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

/**
 * Constructs a FileRobotImageState object from a JSON array state.
 */
class FileRobotImageState
{
    /** -1 to 1 */
    public ?float $brightness = null;

    /** -100 to 100 */
    public ?float $contrast = null;

    /** 0 to 259 */
    public ?float $hue = null;

    /** -2 to 10 */
    public ?float $saturation = null;

    /** -2 to 2 */
    public ?float $value = null;

    /** 0 to 100 */
    public ?float $blurRadius = null;

    /** 0 to 200 */
    public ?float $warmth = null;

    /** @var string[] Order of filters */
    public array $finetuneOrder = [];

    /** Crop X coordinate */
    public ?float $cropX = null;

    /** Crop Y coordinate */
    public ?float $cropY = null;

    /** Crop width */
    public ?float $cropWidth = null;

    /** Crop height */
    public ?float $cropHeight = null;

    /** Rotation */
    public ?int $rotation = null;

    /** Flipped X */
    public bool $isFlippedX = false;

    /** Flipped Y */
    public bool $isFlippedY = false;

    /** Resize width */
    public ?int $resizeWidth = null;

    /** Resize height */
    public ?int $resizeHeight = null;

    /** Filter */
    public ?string $filter = null;

    public function __construct(array $json)
    {
        if ($order = $json['finetunes']) {
            foreach ($order as $key) {
                $this->finetuneOrder[] = $key;
            }
        }

        if ($props = $json['finetunesProps']) {
            $this->_set($props, 'brightness');
            $this->_set($props, 'contrast');
            $this->_set($props, 'hue');
            $this->_set($props, 'saturation');
            $this->_set($props, 'value');
            $this->_set($props, 'blurRadius');
            $this->_set($props, 'warmth');
        }

        if ($props = $json['adjustments']) {
            if ($crop = $props['crop']) {
                $this->_set($crop, 'x', 'cropX');
                $this->_set($crop, 'y', 'cropY');
                $this->_set($crop, 'width', 'cropWidth');
                $this->_set($crop, 'height', 'cropHeight');
            }
            $this->_set($props, 'rotation');
            $this->_set($props, 'isFlippedX');
            $this->_set($props, 'isFlippedY');
        }

        if ($filter = $json['filter']) {
            // https://github.com/scaleflex/filerobot-image-editor/blob/7113bf4968d97f41381f4a2965a59defd44562c8/packages/react-filerobot-image-editor/src/components/tools/Filters/Filters.constants.js#L8
            $this->filter = $filter;
        }

        if ($resize = $json['resize']) {
            $this->_set($resize, 'width', 'resizeWidth');
            $this->_set($resize, 'height', 'resizeHeight');
        }
    }

    private function _set(array $parent, string $key, string $ckey = null): void
    {
        $ckey ??= $key;
        if (\array_key_exists($key, $parent)) {
            $this->{$ckey} = $parent[$key];
        }
    }
}

/**
 * Applies a FileRobotImageState to an Imagick object.
 */
class FileRobotMagick
{
    private \Imagick $image;
    private FileRobotImageState $state;

    public function __construct(\Imagick $image, array $state)
    {
        $this->image = $image;
        $this->state = new FileRobotImageState($state);
    }

    public function apply(): \Imagick
    {
        // Ensure the image is in the correct colorspace
        if (\Imagick::COLORSPACE_SRGB !== $this->image->getColorspace()) {
            $this->image->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
        }

        // Orient the image
        $this->image->autoOrient();

        $this->applyCrop();
        $this->applyFlipRotation();
        $this->applyResize();

        foreach ($this->state->finetuneOrder as $key) {
            $method = 'apply'.$key;
            if (!method_exists($this, $method)) {
                throw new \Exception('Unknown finetune: '.$key);
            }
            $this->{$method}();
        }

        if ($this->state->filter) {
            $method = 'applyFilter'.$this->state->filter;
            if (!method_exists($this, $method)) {
                throw new \Exception('Unknown filter: '.$this->state->filter);
            }
            $this->{$method}();
        }

        return $this->image;
    }

    protected function applyCrop(): void
    {
        if ($this->state->cropX || $this->state->cropY || $this->state->cropWidth || $this->state->cropHeight) {
            $iw = $this->image->getImageWidth();
            $ih = $this->image->getImageHeight();
            $this->image->cropImage(
                (int) (($this->state->cropWidth ?? 1) * $iw),
                (int) (($this->state->cropHeight ?? 1) * $ih),
                (int) (($this->state->cropX ?? 0) * $iw),
                (int) (($this->state->cropY ?? 0) * $ih),
            );
        }
    }

    protected function applyFlipRotation(): void
    {
        if ($this->state->isFlippedX) {
            $this->image->flopImage();
        }
        if ($this->state->isFlippedY) {
            $this->image->flipImage();
        }
        if ($this->state->rotation) {
            $this->image->rotateImage(new \ImagickPixel(), $this->state->rotation);
        }
    }

    protected function applyResize(): void
    {
        if ($this->state->resizeWidth || $this->state->resizeHeight) {
            $this->image->resizeImage(
                $this->state->resizeWidth ?? 0,
                $this->state->resizeHeight ?? 0,
                \Imagick::FILTER_LANCZOS,
                1,
            );
        }
    }

    protected function applyBrighten(?float $value = null): void
    {
        $brightness = $value ?? $this->state->brightness ?? 0;
        if (0 === $brightness) {
            return;
        }

        // https://github.com/konvajs/konva/blob/f0e18b09079175404a1026363689f8f89eae0749/src/filters/Brighten.ts#L15-L29
        $this->image->evaluateImage(\Imagick::EVALUATE_ADD, $brightness * 255 * 255, \Imagick::CHANNEL_ALL);
    }

    protected function applyContrast(?float $value = null): void
    {
        $contrast = $value ?? $this->state->contrast ?? 0;
        if (0 === $contrast) {
            return;
        }

        // https://github.com/konvajs/konva/blob/f0e18b09079175404a1026363689f8f89eae0749/src/filters/Contrast.ts#L15-L59
        // m = ((a + 100) / 100) ** 2       // slope
        // y = (x - 0.5) * m + 0.5
        // y = mx + (0.5 * (1 - m))         // simplify
        $m = (($contrast + 100) / 100) ** 2;
        $c = 0.5 * (1 - $m);

        $this->image->functionImage(\Imagick::FUNCTION_POLYNOMIAL, [$m, $c], \Imagick::CHANNEL_ALL);
    }

    protected function applyHSV(?float $hue = null, ?float $saturation = null, ?float $value = null): void
    {
        $hue ??= $this->state->hue ?? 0;
        $saturation ??= $this->state->saturation ?? 0;
        $value ??= $this->state->value ?? 0;

        if (0 === $hue && 0 === $saturation && 0 === $value) {
            return;
        }

        $h = abs($hue + 360) % 360;
        $s = 2 ** $saturation;
        $v = 2 ** $value;

        // https://github.com/konvajs/konva/blob/f0e18b09079175404a1026363689f8f89eae0749/src/filters/HSV.ts#L17-L63
        $vsu = $v * $s * cos(($h * M_PI) / 180);
        $vsw = $v * $s * sin(($h * M_PI) / 180);

        $rr = 0.299 * $v + 0.701 * $vsu + 0.167 * $vsw;
        $rg = 0.587 * $v - 0.587 * $vsu + 0.33 * $vsw;
        $rb = 0.114 * $v - 0.114 * $vsu - 0.497 * $vsw;
        $gr = 0.299 * $v - 0.299 * $vsu - 0.328 * $vsw;
        $gg = 0.587 * $v + 0.413 * $vsu + 0.035 * $vsw;
        $gb = 0.114 * $v - 0.114 * $vsu + 0.293 * $vsw;
        $br = 0.299 * $v - 0.3 * $vsu + 1.25 * $vsw;
        $bg = 0.587 * $v - 0.586 * $vsu - 1.05 * $vsw;
        $bb = 0.114 * $v + 0.886 * $vsu - 0.2 * $vsw;

        /** @psalm-suppress InvalidArgument */
        $this->image->colorMatrixImage([
            $rr, $rg, $rb, 0, 0,
            $gr, $gg, $gb, 0, 0,
            $br, $bg, $bb, 0, 0,
            0, 0, 0, 1, 0,
            0, 0, 0, 0, 1,
        ]);
    }

    protected function applyBlur(): void
    {
        if ($this->state->blurRadius <= 0) {
            return;
        }

        // https://github.com/konvajs/konva/blob/f0e18b09079175404a1026363689f8f89eae0749/src/filters/Blur.ts#L834
        $sigma = min(round($this->state->blurRadius * 1.5), 100);
        $this->image->blurImage(0, $sigma);
    }

    protected function applyWarmth(): void
    {
        // https://github.com/scaleflex/filerobot-image-editor/blob/7113bf4968d97f41381f4a2965a59defd44562c8/packages/react-filerobot-image-editor/src/custom/finetunes/Warmth.js#L17-L28
        $warmth = ($this->state->warmth ?? 0);
        if ($warmth <= 0) {
            return;
        }

        // Add to red channel, subtract from blue channel
        $this->image->evaluateImage(\Imagick::EVALUATE_ADD, $warmth * 255, \Imagick::CHANNEL_RED);
        $this->image->evaluateImage(\Imagick::EVALUATE_SUBTRACT, $warmth * 255, \Imagick::CHANNEL_BLUE);
    }

    // https://github.com/scaleflex/filerobot-image-editor/blob/7113bf4968d97f41381f4a2965a59defd44562c8/packages/react-filerobot-image-editor/src/components/tools/Filters/Filters.constants.js#L8
    protected function applyFilterInvert(): void
    {
        $this->image->negateImage(false);
    }

    protected function applyFilterBlackAndWhite(): void
    {
        // https://github.com/scaleflex/filerobot-image-editor/blob/7113bf4968d97f41381f4a2965a59defd44562c8/packages/react-filerobot-image-editor/src/custom/filters/BlackAndWhite.js
        $this->image->thresholdImage(100 * 255);
    }

    protected function applyFilterSepia(): void
    {
        // https://github.com/konvajs/konva/blob/master/src/filters/Sepia.ts
        /** @psalm-suppress InvalidArgument */
        $this->image->colorMatrixImage([
            0.393, 0.769, 0.189, 0, 0,
            0.349, 0.686, 0.168, 0, 0,
            0.272, 0.534, 0.131, 0, 0,
            0, 0, 0, 1, 0,
            0, 0, 0, 0, 1,
        ]);
    }

    protected function applyFilterSolarize(): void
    {
        // https://github.com/konvajs/konva/blob/master/src/filters/Solarize.ts
        $this->image->solarizeImage(128 * 255);
    }

    protected function applyFilterClarendon(): void
    {
        $this->applyBaseFilterBrightness(0.1);
        $this->applyBaseFilterContrast(0.1);
        $this->applyBaseFilterSaturation(0.15);
    }

    protected function applyFilterGingham(): void
    {
        $this->applyBaseFilterSepia(0.04);
        $this->applyBaseFilterContrast(-0.15);
    }

    protected function applyFilterMoon(): void
    {
        $this->applyBaseFilterGrayscale();
        $this->applyBaseFilterBrightness(0.1);
    }

    protected function applyFilterLark(): void
    {
        $this->applyBaseFilterBrightness(0.08);
        $this->applyBaseFilterAdjustRGB(1, 1.03, 1.05);
        $this->applyBaseFilterSaturation(0.12);
    }

    protected function applyFilterReyes(): void
    {
        $this->applyBaseFilterSepia(0.4);
        $this->applyBaseFilterBrightness(0.13);
        $this->applyBaseFilterContrast(-0.05);
    }

    protected function applyFilterJuno(): void
    {
        $this->applyBaseFilterAdjustRGB(1.01, 1.04, 1);
        $this->applyBaseFilterSaturation(0.3);
    }

    protected function applyFilterSlumber(): void
    {
        $this->applyBaseFilterBrightness(0.1);
        $this->applyBaseFilterSaturation(-0.5);
    }

    protected function applyFilterCrema(): void
    {
        $this->applyBaseFilterAdjustRGB(1.04, 1, 1.02);
        $this->applyBaseFilterSaturation(-0.05);
    }

    protected function applyFilterLudwig(): void
    {
        $this->applyBaseFilterBrightness(0.05);
        $this->applyBaseFilterSaturation(-0.03);
    }

    protected function applyFilterAden(): void
    {
        $this->applyBaseFilterColorFilter(228, 130, 225, 0.13);
        $this->applyBaseFilterSaturation(-0.2);
    }

    protected function applyFilterPerpetua(): void
    {
        $this->applyBaseFilterAdjustRGB(1.05, 1.1, 1);
    }

    protected function applyFilterAmaro(): void
    {
        $this->applyBaseFilterSaturation(0.3);
        $this->applyBaseFilterBrightness(0.15);
    }

    protected function applyFilterMayfair(): void
    {
        $this->applyBaseFilterColorFilter(230, 115, 108, 0.05);
        $this->applyBaseFilterSaturation(0.15);
    }

    protected function applyFilterRise(): void
    {
        $this->applyBaseFilterColorFilter(255, 170, 0, 0.1);
        $this->applyBaseFilterBrightness(0.09);
        $this->applyBaseFilterSaturation(0.1);
    }

    protected function applyFilterHudson(): void
    {
        $this->applyBaseFilterAdjustRGB(1, 1, 1.25);
        $this->applyBaseFilterContrast(0.1);
        $this->applyBaseFilterBrightness(0.15);
    }

    protected function applyFilterValencia(): void
    {
        $this->applyBaseFilterColorFilter(255, 225, 80, 0.08);
        $this->applyBaseFilterSaturation(0.1);
        $this->applyBaseFilterContrast(0.05);
    }

    protected function applyFilterXpro2(): void
    {
        $this->applyBaseFilterColorFilter(255, 255, 0, 0.07);
        $this->applyBaseFilterSaturation(0.2);
        $this->applyBaseFilterContrast(0.15);
    }

    protected function applyFilterSierra(): void
    {
        $this->applyBaseFilterContrast(-0.15);
        $this->applyBaseFilterSaturation(0.1);
    }

    protected function applyFilterWillow(): void
    {
        $this->applyBaseFilterGrayscale();
        $this->applyBaseFilterColorFilter(100, 28, 210, 0.03);
        $this->applyBaseFilterBrightness(0.1);
    }

    protected function applyFilterLoFi(): void
    {
        $this->applyBaseFilterContrast(0.15);
        $this->applyBaseFilterSaturation(0.2);
    }

    protected function applyFilterInkwell(): void
    {
        $this->applyBaseFilterGrayscale();
    }

    protected function applyFilterHefe(): void
    {
        $this->applyBaseFilterContrast(0.1);
        $this->applyBaseFilterSaturation(0.15);
    }

    protected function applyFilterNashville(): void
    {
        $this->applyBaseFilterColorFilter(220, 115, 188, 0.12);
        $this->applyBaseFilterContrast(-0.05);
    }

    protected function applyFilterStinson(): void
    {
        $this->applyBaseFilterBrightness(0.1);
        $this->applyBaseFilterSepia(0.3);
    }

    protected function applyFilterVesper(): void
    {
        $this->applyBaseFilterColorFilter(255, 225, 0, 0.05);
        $this->applyBaseFilterBrightness(0.06);
        $this->applyBaseFilterContrast(0.06);
    }

    protected function applyFilterEarlybird(): void
    {
        $this->applyBaseFilterColorFilter(255, 165, 40, 0.2);
    }

    protected function applyFilterBrannan(): void
    {
        $this->applyBaseFilterContrast(0.2);
        $this->applyBaseFilterColorFilter(140, 10, 185, 0.1);
    }

    protected function applyFilterSutro(): void
    {
        $this->applyBaseFilterBrightness(-0.1);
        $this->applyBaseFilterContrast(-0.1);
    }

    protected function applyFilterToaster(): void
    {
        $this->applyBaseFilterSepia(0.1);
        $this->applyBaseFilterColorFilter(255, 145, 0, 0.2);
    }

    protected function applyFilterWalden(): void
    {
        $this->applyBaseFilterBrightness(0.1);
        $this->applyBaseFilterColorFilter(255, 255, 0, 0.2);
    }

    protected function applyFilterNinteenSeventySeven(): void
    {
        $this->applyBaseFilterColorFilter(255, 25, 0, 0.15);
        $this->applyBaseFilterBrightness(0.1);
    }

    protected function applyFilterKelvin(): void
    {
        $this->applyBaseFilterColorFilter(255, 140, 0, 0.1);
        $this->applyBaseFilterAdjustRGB(1.15, 1.05, 1);
        $this->applyBaseFilterSaturation(0.35);
    }

    protected function applyFilterMaven(): void
    {
        $this->applyBaseFilterColorFilter(225, 240, 0, 0.1);
        $this->applyBaseFilterSaturation(0.25);
        $this->applyBaseFilterContrast(0.05);
    }

    protected function applyFilterGinza(): void
    {
        $this->applyBaseFilterSepia(0.06);
        $this->applyBaseFilterBrightness(0.1);
    }

    protected function applyFilterSkyline(): void
    {
        $this->applyBaseFilterSaturation(0.35);
        $this->applyBaseFilterBrightness(0.1);
    }

    protected function applyFilterDogpatch(): void
    {
        $this->applyBaseFilterContrast(0.15);
        $this->applyBaseFilterBrightness(0.1);
    }

    protected function applyFilterBrooklyn(): void
    {
        $this->applyBaseFilterColorFilter(25, 240, 252, 0.05);
        $this->applyBaseFilterSepia(0.3);
    }

    protected function applyFilterHelena(): void
    {
        $this->applyBaseFilterColorFilter(208, 208, 86, 0.2);
        $this->applyBaseFilterContrast(0.15);
    }

    protected function applyFilterAshby(): void
    {
        $this->applyBaseFilterColorFilter(255, 160, 25, 0.1);
        $this->applyBaseFilterBrightness(0.1);
    }

    protected function applyFilterCharmes(): void
    {
        $this->applyBaseFilterColorFilter(255, 50, 80, 0.12);
        $this->applyBaseFilterContrast(0.05);
    }

    protected function applyBaseFilterBrightness(float $value): void
    {
        // https://github.com/scaleflex/filerobot-image-editor/blob/7113bf4968d97f41381f4a2965a59defd44562c8/packages/react-filerobot-image-editor/src/custom/filters/BaseFilters.js#L2
        $this->applyBrighten($value);
    }

    protected function applyBaseFilterContrast(float $value): void
    {
        // https://github.com/scaleflex/filerobot-image-editor/blob/7113bf4968d97f41381f4a2965a59defd44562c8/packages/react-filerobot-image-editor/src/custom/filters/BaseFilters.js#L14
        $value *= 255;

        // y = m * (x - 128) + 128
        // y = m * x + (128 * (1 - m))
        $m = (259 * ($value + 255)) / (255 * (259 - $value));
        $c = 0.5 * (1 - $m);

        $this->image->functionImage(\Imagick::FUNCTION_POLYNOMIAL, [$m, $c], \Imagick::CHANNEL_ALL);
    }

    protected function applyBaseFilterSaturation(float $value): void
    {
        // https://github.com/scaleflex/filerobot-image-editor/blob/7113bf4968d97f41381f4a2965a59defd44562c8/packages/react-filerobot-image-editor/src/custom/filters/BaseFilters.js#L24
        $this->applyHSV(0, $value, 0); // lazy
    }

    protected function applyBaseFilterGrayscale(): void
    {
        // https://github.com/scaleflex/filerobot-image-editor/blob/7113bf4968d97f41381f4a2965a59defd44562c8/packages/react-filerobot-image-editor/src/custom/filters/BaseFilters.js#L38
        //  y = 0.2126 * r + 0.7152 * g + 0.0722 * b;
        /** @psalm-suppress InvalidArgument */
        $this->image->colorMatrixImage([
            0.2126, 0.7152, 0.0722, 0, 0,
            0.2126, 0.7152, 0.0722, 0, 0,
            0.2126, 0.7152, 0.0722, 0, 0,
            0, 0, 0, 1, 0,
            0, 0, 0, 0, 1,
        ]);
    }

    protected function applyBaseFilterSepia(float $value): void
    {
        // https://github.com/scaleflex/filerobot-image-editor/blob/7113bf4968d97f41381f4a2965a59defd44562c8/packages/react-filerobot-image-editor/src/custom/filters/BaseFilters.js#L46
        /** @psalm-suppress InvalidArgument */
        $this->image->colorMatrixImage([
            1 - 0.607 * $value, 0.769 * $value, 0.189 * $value, 0, 0,
            0.349 * $value, 1 - 0.314 * $value, 0.168 * $value, 0, 0,
            0.272 * $value, 0.534 * $value, 1 - 0.869 * $value, 0, 0,
            0, 0, 0, 1, 0,
            0, 0, 0, 0, 1,
        ]);
    }

    protected function applyBaseFilterAdjustRGB(float $r, float $g, float $b): void
    {
        // https://github.com/scaleflex/filerobot-image-editor/blob/7113bf4968d97f41381f4a2965a59defd44562c8/packages/react-filerobot-image-editor/src/custom/filters/BaseFilters.js#L57
        /** @psalm-suppress InvalidArgument */
        $this->image->colorMatrixImage([
            $r, 0, 0, 0, 0,
            0, $g, 0, 0, 0,
            0, 0, $b, 0, 0,
            0, 0, 0, 1, 0,
            0, 0, 0, 0, 1,
        ]);
    }

    protected function applyBaseFilterColorFilter(float $r, float $g, float $b, float $v): void
    {
        // https://github.com/scaleflex/filerobot-image-editor/blob/7113bf4968d97f41381f4a2965a59defd44562c8/packages/react-filerobot-image-editor/src/custom/filters/BaseFilters.js#L63
        // y = x - (x - k) * v = (1 - v) * x + k * v
        $this->image->evaluateImage(\Imagick::EVALUATE_MULTIPLY, 1 - $v, \Imagick::CHANNEL_ALL);
        $this->image->evaluateImage(\Imagick::EVALUATE_ADD, $v * $r * 255, \Imagick::CHANNEL_RED);
        $this->image->evaluateImage(\Imagick::EVALUATE_ADD, $v * $g * 255, \Imagick::CHANNEL_GREEN);
        $this->image->evaluateImage(\Imagick::EVALUATE_ADD, $v * $b * 255, \Imagick::CHANNEL_BLUE);
    }
}
