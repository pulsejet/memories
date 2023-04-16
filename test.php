<?php

class ImageState {
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

    public function __construct(array $json)
    {
        if ($order = $json['finetunes']) {
            foreach ($order as $key) {
                switch ($key) {
                    case 'Brighten':
                    case 'Contrast':
                    case 'HSV':
                    case 'Blur':
                    case 'Warmth':
                        $this->finetuneOrder[] = $key;
                        break;
                }
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
        }
    }

    private function _set(array $parent, string $key, string $ckey = null) {
        $ckey ??= $key;
        if (array_key_exists($key, $parent)) {
            $this->$ckey = $parent[$key];
        }
    }
}

class KonvaMagick {
    private \Imagick $image;
    private ImageState $state;

    public function __construct(\Imagick $image, ImageState $state)
    {
        $this->image = $image;
        $this->state = $state;
    }

    public function apply() {
        $this->applyCrop();

        foreach ($this->state->finetuneOrder as $key) {
            $method = 'apply' . $key;
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

    protected function applyBrighten() {
        $brightness = $this->state->brightness ?? 0;
        if ($brightness === 0) {
            return;
        }

        // https://github.com/konvajs/konva/blob/f0e18b09079175404a1026363689f8f89eae0749/src/filters/Brighten.ts#L15-L29
        $this->image->evaluateImage(\Imagick::EVALUATE_ADD, $brightness * 255 * 255, \Imagick::CHANNEL_ALL);
    }

    protected function applyContrast() {
        $contrast = $this->state->contrast ?? 0;
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

    protected function applyHSV() {
        if (!$this->state->hue && !$this->state->saturation && !$this->state->value) {
            return;
        }

        $h = abs(($this->state->hue ?? 0) + 360) % 360;
        $s = 2 ** ($this->state->saturation ?? 0);
        $v = 2 ** ($this->state->value ?? 0);

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
}

// Create new ImageState object
$imageState = new ImageState([
    'finetunes' => ['Blur', 'Warmth', 'HSV', 'Contrast', 'Brighten'],
    'finetuneProps' => [
        'brightness' => 0,
        'contrast' => 49,
        'hue' => 0,
        'saturation' => 0,
        'value' => 0,
        'blurRadius' => 0,
        'warmth' => 0,
    ],
    'adjustments' =>[
        'crop' => [
            'x' => 0.04811054824217651,
            'y' => 0.30121176094862184,
            'width' => 0.47661152675402463,
            'height' => 0.47661153565936554,
        ],
    ]
]);

// Open test image file imagick
$image = new \Imagick('test.jpg');
$image->setResourceLimit(\Imagick::RESOURCETYPE_THREAD, 4);

// Apply image state
(new KonvaMagick($image, $imageState))->apply();

//resize to max width
$image->resizeImage(400, 0, \Imagick::FILTER_LANCZOS, 1);

// Write to out.jpg
$image->writeImage('out.jpg');