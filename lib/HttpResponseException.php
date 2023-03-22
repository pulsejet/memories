<?php

namespace OCA\Memories;

use OCP\AppFramework\Http;

class HttpResponseException extends \Exception
{
    public Http\Response $response;

    public function __construct(Http\Response $response)
    {
        $this->response = $response;
    }
}
