<?php

declare(strict_types=1);

namespace OCA\Memories\Controller;

use OCP\AppFramework\Http\TemplateResponse;

/** @psalm-suppress MissingTemplateParam */
class TemplateResponsePatch extends TemplateResponse
{
    public function render()
    {
        $content = parent::render();

        // Patch the render response to replace the viewport meta tag
        return preg_replace(
            '/<meta\s+name="viewport"\s+content="[^"]*"\s*\/?>/',
            '<meta name="viewport" content="width=device-width, viewport-fit=cover, initial-scale=1, minimum-scale=1.0, maximum-scale=1, user-scalable=no">',
            $content,
        );
    }
}
