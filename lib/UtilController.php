<?php

declare(strict_types=1);

namespace OCA\Memories;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;

trait UtilController
{
    /**
     * Run a function and catch exceptions to return HTTP response.
     *
     * @param \Closure(): Http\Response $closure
     */
    public static function guardEx(\Closure $closure): Http\Response
    {
        try {
            return $closure();
        } catch (\OCA\Memories\HttpResponseException $e) {
            return $e->response;
        } catch (\Exception $e) {
            return new DataResponse([
                'message' => $e->getMessage(),
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Return a callback response with guarded exceptions.
     *
     * @param \Closure(Http\IOutput): void $closure
     */
    public static function guardExDirect(\Closure $closure): Http\Response
    {
        /** @psalm-suppress MissingTemplateParam */
        return new class($closure) extends Http\Response implements Http\ICallbackResponse {
            /**
             * @param \Closure(Http\IOutput): void $closure
             */
            public function __construct(private \Closure $closure)
            {
                parent::__construct();
            }

            public function callback(Http\IOutput $output): void
            {
                try {
                    ($this->closure)($output);
                } catch (\OCA\Memories\HttpResponseException $e) {
                    $res = $e->response;
                    $output->setHttpResponseCode($res->getStatus());
                    if ($res instanceof Http\DataResponse) {
                        $output->setHeader('Content-Type: application/json');
                        $output->setOutput(json_encode($res->getData()));
                    } else {
                        $output->setOutput($res->render());
                    }
                } catch (\Exception $e) {
                    $output->setHttpResponseCode(Http::STATUS_INTERNAL_SERVER_ERROR);
                    $output->setHeader('Content-Type: application/json');
                    $output->setOutput(json_encode([
                        'message' => $e->getMessage(),
                    ]));
                }
            }
        };
    }

    /**
     * Get the current user.
     *
     * @throws \OCA\Memories\HttpResponseException if the user is not logged in
     */
    public static function getUser(): \OCP\IUser
    {
        return \OC::$server->get(\OCP\IUserSession::class)->getUser()
            ?? throw Exceptions::NotLoggedIn();
    }

    /**
     * Get the current user ID.
     *
     * @throws \OCA\Memories\HttpResponseException if the user is not logged in
     */
    public static function getUID(): string
    {
        return self::getUser()->getUID();
    }

    /**
     * Check if the user is logged in.
     */
    public static function isLoggedIn(): bool
    {
        return null !== \OC::$server->get(\OCP\IUserSession::class)->getUser();
    }

    /**
     * Get a user's home folder.
     *
     * @param null|string $uid User ID, or null for the user
     *
     * @throws \OCA\Memories\HttpResponseException if the user is not logged in
     */
    public static function getUserFolder(?string $uid = null): \OCP\Files\Folder
    {
        return \OC::$server->get(\OCP\Files\IRootFolder::class)
            ->getUserFolder($uid ?? self::getUID())
        ;
    }

    /**
     * Get the language code for the current user.
     */
    public static function getUserLang(): string
    {
        // Default language
        $config = \OC::$server->get(\OCP\IConfig::class);
        $default = $config->getSystemValue('default_language', 'en');

        try {
            // Get language of the user
            return $config->getUserValue(self::getUID(), 'core', 'lang', $default);
        } catch (\Exception) {
            // Fallback to server language
            return $default;
        }
    }
}
