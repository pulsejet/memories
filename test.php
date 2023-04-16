<?php

class FileRobotImageState {
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

    /** Order of filters */
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

    /** Filter */
    public ?string $filter = null;

    public function __construct(array $json)
    {
        if ($order = $json['finetunes']) {
            foreach ($order as $key) {
                $this->finetuneOrder[] = $key;
            }
        }

        if ($props = $json['finetuneProps']) {
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
    }

    private function _set(array $parent, string $key, string $ckey = null) {
        $ckey ??= $key;
        if (array_key_exists($key, $parent)) {
            $this->$ckey = $parent[$key];
        }
    }
}

class FileRobotMagick {
    private \Imagick $image;
    private FileRobotImageState $state;

    public function __construct(\Imagick $image, FileRobotImageState $state)
    {
        $this->image = $image;
        $this->state = $state;
    }

    public function apply() {
        $this->applyCrop();
        $this->applyFlipRotation();

        foreach ($this->state->finetuneOrder as $key) {
            $method = 'apply' . $key;
            if (!method_exists($this, $method)) {
                throw new \Exception('Unknown finetune: ' . $key);
            }
            $this->$method();
        }

        if ($this->state->filter) {
            $method = 'applyFilter' . $this->state->filter;
            if (!method_exists($this, $method)) {
                throw new \Exception('Unknown filter: ' . $this->state->filter);
            }
            $this->$method();
        }

        return $this->image;
    }

    protected function applyCrop() {
        if ($this->state->cropX || $this->state->cropY || $this->state->cropWidth || $this->state->cropHeight) {
            $iw = $this->image->getImageWidth();
            $ih = $this->image->getImageHeight();
            $this->image->cropImage(
                (int) (($this->state->cropWidth ?? 1) * $iw),
                (int) (($this->state->cropHeight ?? 1) * $ih),
                (int) (($this->state->cropX ?? 0) * $iw),
                (int) (($this->state->cropY ?? 0) * $ih)
            );
        }
    }

    protected function applyFlipRotation() {
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

    protected function applyBrighten(?float $value = null) {
        $brightness = $value ?? $this->state->brightness ?? 0;
        if ($brightness === 0) {
            return;
        }

        // https://github.com/konvajs/konva/blob/f0e18b09079175404a1026363689f8f89eae0749/src/filters/Brighten.ts#L15-L29
        $this->image->evaluateImage(\Imagick::EVALUATE_ADD, $brightness * 255 * 255, \Imagick::CHANNEL_ALL);
    }

    protected function applyContrast(?float $value = null) {
        $contrast = $value ?? $this->state->contrast ?? 0;
        if ($contrast === 0) {
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

    protected function applyHSV(?float $hue = null, ?float $saturation = null, ?float $value = null) {
        $hue ??= $this->state->hue ?? 0;
        $saturation ??= $this->state->saturation ?? 0;
        $value ??= $this->state->value ?? 0;

        if ($hue === 0 && $saturation === 0 && $value === 0) {
            return;
        }

        $h = abs(($hue ?? 0) + 360) % 360;
        $s = 2 ** ($saturation ?? 0);
        $v = 2 ** ($value ?? 0);

        // https://github.com/konvajs/konva/blob/f0e18b09079175404a1026363689f8f89eae0749/src/filters/HSV.ts#L17-L63
        $vsu = $v * $s * cos(($h * pi()) / 180);
        $vsw = $v * $s * sin(($h * pi()) / 180);

        $rr = 0.299 * $v + 0.701 * $vsu + 0.167 * $vsw;
        $rg = 0.587 * $v - 0.587 * $vsu + 0.33 * $vsw;
        $rb = 0.114 * $v - 0.114 * $vsu - 0.497 * $vsw;
        $gr = 0.299 * $v - 0.299 * $vsu - 0.328 * $vsw;
        $gg = 0.587 * $v + 0.413 * $vsu + 0.035 * $vsw;
        $gb = 0.114 * $v - 0.114 * $vsu + 0.293 * $vsw;
        $br = 0.299 * $v - 0.3 * $vsu + 1.25 * $vsw;
        $bg = 0.587 * $v - 0.586 * $vsu - 1.05 * $vsw;
        $bb = 0.114 * $v + 0.886 * $vsu - 0.2 * $vsw;

        $colorMatrix = [
            $rr, $rg, $rb, 0, 0,
            $gr, $gg, $gb, 0, 0,
            $br, $bg, $bb, 0, 0,
            0, 0, 0, 1, 0,
            0, 0, 0, 0, 1,
        ];

        $this->image->colorMatrixImage($colorMatrix);
    }

    protected function applyBlur() {
        if ($this->state->blurRadius <= 0) {
            return;
        }

        // https://github.com/konvajs/konva/blob/f0e18b09079175404a1026363689f8f89eae0749/src/filters/Blur.ts#L834
        $sigma = min(round($this->state->blurRadius * 1.5), 100);
        $this->image->blurImage(0, $sigma);
    }

    protected function applyWarmth() {
        // https://github.com/scaleflex/filerobot-image-editor/blob/7113bf4968d97f41381f4a2965a59defd44562c8/packages/react-filerobot-image-editor/src/custom/finetunes/Warmth.js#L17-L28
        $warmth = ($this->state->warmth ?? 0);
        if ($warmth <= 0) {
            return;
        }

        // Add to red channel, subtract from blue channel
        $this->image->evaluateImage(\Imagick::EVALUATE_ADD, $warmth*255, \Imagick::CHANNEL_RED);
        $this->image->evaluateImage(\Imagick::EVALUATE_SUBTRACT, $warmth*255, \Imagick::CHANNEL_BLUE);
    }

    protected function applyFilterInvert() {
        $this->image->negateImage(false);
    }

    protected function applyFilterBlackAndWhite() {
        // https://github.com/scaleflex/filerobot-image-editor/blob/7113bf4968d97f41381f4a2965a59defd44562c8/packages/react-filerobot-image-editor/src/custom/filters/BlackAndWhite.js
        $this->image->thresholdImage(100 * 255);
    }

    protected function applyFilterSepia() {
        // https://github.com/konvajs/konva/blob/master/src/filters/Sepia.ts
        $this->image->colorMatrixImage([
            0.393, 0.769, 0.189, 0, 0,
            0.349, 0.686, 0.168, 0, 0,
            0.272, 0.534, 0.131, 0, 0,
            0, 0, 0, 1, 0,
            0, 0, 0, 0, 1,
        ]);
    }

    protected function applyFilterSolarize() {
        // https://github.com/konvajs/konva/blob/master/src/filters/Solarize.ts
        $this->image->solarizeImage(128 * 255);
    }

    protected function applyFilterClarendon() {
        // https://github.com/scaleflex/filerobot-image-editor/blob/7113bf4968d97f41381f4a2965a59defd44562c8/packages/react-filerobot-image-editor/src/custom/filters/Clarendon.js
        $this->applyBaseFilterBrightness(0.1);
        $this->applyContrast(10); // TODO: this is wrong
        $this->applyHSV(0, 0.15, 0); // TODO: this is wrong
    }

    protected function applyFilterGingham() {
        // https://github.com/scaleflex/filerobot-image-editor/blob/7113bf4968d97f41381f4a2965a59defd44562c8/packages/react-filerobot-image-editor/src/custom/filters/Gingham.js
        // ...
    }

    protected function applyBaseFilterBrightness(float $value) {
        // https://github.com/scaleflex/filerobot-image-editor/blob/7113bf4968d97f41381f4a2965a59defd44562c8/packages/react-filerobot-image-editor/src/custom/filters/BaseFilters.js#L2
        $this->applyBrighten($value);
    }

    protected function applyFilterTest() {
        $this->applyFilterClarendon();
    }
}

// Create new ImageState object
$imageState = new FileRobotImageState([
    'finetunes' => ['Blur', 'Warmth', 'HSV', 'Contrast', 'Brighten'],
    'finetuneProps' => [
        'brightness' => 0,
        'contrast' => 0,
        'hue' => 0,
        'saturation' => 0,
        'value' => 0,
        'blurRadius' => 0,
        'warmth' => 0,
    ],
    'filter' => 'Test',
    'adjustments' =>[
        // 'crop' => [
        //     'x' => 0.04811054824217651,
        //     'y' => 0.30121176094862184,
        //     'width' => 0.47661152675402463,
        //     'height' => 0.47661153565936554,
        // ],
        // 'rotation' => 0,
        // 'isFlippedX' => false,
        // 'isFlippedY' => false,
    ]
]);

// Open test image file imagick
$image = new \Imagick('test.jpg');
$image->setResourceLimit(\Imagick::RESOURCETYPE_THREAD, 4);

// Apply image state
(new FileRobotMagick($image, $imageState))->apply();

//resize to max width
$image->resizeImage(800, 0, \Imagick::FILTER_LANCZOS, 1);

// Write to out.jpg
$image->writeImage('out.jpg');