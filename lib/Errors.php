<?php

declare(strict_types=1);

namespace OCA\Memories;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;

class Errors
{
    public static function Generic(\Exception $e, int $status = Http::STATUS_INTERNAL_SERVER_ERROR): Http\Response
    {
        return new DataResponse([
            'message' => $e->getMessage(),
        ], $status);
    }

    public static function NotLoggedIn(): Http\Response
    {
        return new DataResponse([
            'message' => 'User not logged in',
        ], Http::STATUS_PRECONDITION_FAILED);
    }

    public static function NotEnabled(string $app): Http\Response
    {
        return new DataResponse([
            'message' => "{$app} app not enabled or not the required version.",
        ], Http::STATUS_PRECONDITION_FAILED);
    }

    public static function NoRequestRoot(): Http\Response
    {
        return new DataResponse([
            'message' => 'Request root could not be determined',
        ], Http::STATUS_NOT_FOUND);
    }

    public static function NotFound(string $ctx): Http\Response
    {
        return new DataResponse([
            'message' => "Not found ({$ctx})",
        ], Http::STATUS_NOT_FOUND);
    }

    public static function NotFoundFile($identifier): Http\Response
    {
        return new DataResponse([
            'message' => "File not found ({$identifier})",
        ], Http::STATUS_NOT_FOUND);
    }

    public static function Forbidden(string $ctx): Http\Response
    {
        return new DataResponse([
            'message' => "Forbidden ({$ctx})",
        ], Http::STATUS_FORBIDDEN);
    }

    public static function ForbiddenFileUpdate(string $name): Http\Response
    {
        return new DataResponse([
            'message' => "Forbidden ({$name} cannot be updated)",
        ], Http::STATUS_FORBIDDEN);
    }

    public static function MissingParameter(string $name): Http\Response
    {
        return new DataResponse([
            'message' => "Missing parameter ({$name})",
        ], Http::STATUS_BAD_REQUEST);
    }
}
