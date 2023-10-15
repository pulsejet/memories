<?php

namespace OCA\Memories;

use OCP\AppFramework\Http;

class HttpResponseException extends \Exception
{
    public function __construct(public Http\Response $response) {}
}
