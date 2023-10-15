<?php

declare(strict_types=1);

namespace OCA\Memories;

use OCP\AppFramework\Http;

class HttpResponseException extends \Exception
{
    public function __construct(public Http\Response $response) {}
}
