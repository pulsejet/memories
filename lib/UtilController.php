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
     * @param mixed $function
     */
    public static function guardEx($function): Http\Response
    {
        try {
            return $function();
        } catch (\OCA\Memories\HttpResponseException $e) {
            return $e->response;
        } catch (\Exception $e) {
            return new DataResponse([
                'message' => $e->getMessage(),
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get the current user.
     *
     * @throws \OCA\Memories\HttpResponseException if the user is not logged in
     */
    public static function getUser(): \OCP\IUser
    {
        $user = \OC::$server->get(\OCP\IUserSession::class)->getUser();
        if (null === $user) {
            throw Exceptions::NotLoggedIn();
        }

        return $user;
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
     * @param null|string $uid User ID, or null for current user
     *
     * @throws \OCA\Memories\HttpResponseException if the user is not logged in
     */
    public static function getUserFolder(?string $uid = null): \OCP\Files\Folder
    {
        if (null === $uid) {
            $uid = self::getUID();
        }

        return \OC::$server->get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);
    }

    /**
     * Get the language code for the current user.
     */
    public static function getUserLang(): string
    {
        // Default language
        $config = \OC::$server->get(\OCP\IConfig::class);
        $default = $config->getSystemValue('default_language', 'en');

        // Get UID of the user
        try {
            $uid = self::getUID();
        } catch (\Exception $e) {
            return 'en';
        }

        // Get language of the user
        return $config->getUserValue($uid, 'core', 'lang', $default);
    }
}
