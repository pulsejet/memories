<?php

declare(strict_types=1);

namespace OCA\Memories;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

final class Util extends StaticUtil
{
    public function __construct(
        private LoggerInterface $logger,
        private IUserSession $userSession,
        private IRootFolder $rootFolder,
    ) {}

    /**
     * Run a function and catch exceptions to return HTTP response.
     *
     * @param \Closure(): Http\Response $closure
     */
    public function guardEx(\Closure $closure): Http\Response
    {
        try {
            return $closure();
        } catch (\OCA\Memories\HttpResponseException $e) {
            return $e->response;
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage(), ['trace' => $e->getTrace()]);

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
    public function guardExDirect(\Closure $closure): Http\Response
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

            #[\Override]
            public function callback(Http\IOutput $output): void
            {
                try {
                    ($this->closure)($output);
                } catch (\OCA\Memories\HttpResponseException $e) {
                    $res = $e->response;
                    $output->setHttpResponseCode($res->getStatus());
                    $output->setHeader($this->getStatusHeader($res->getStatus()));
                    if ($res instanceof Http\DataResponse) {
                        $output->setHeader('Content-Type: application/json');
                        $output->setOutput(json_encode($res->getData()));
                    } else {
                        $output->setOutput($res->render());
                    }
                } catch (\Exception $e) {
                    $output->setHttpResponseCode(Http::STATUS_INTERNAL_SERVER_ERROR);
                    $output->setHeader($this->getStatusHeader(Http::STATUS_INTERNAL_SERVER_ERROR));
                    $output->setHeader('Content-Type: application/json');
                    $output->setOutput(json_encode([
                        'message' => $e->getMessage(),
                    ]));
                }
            }

            /**
             * Status must be sent via header() too: \OC\AppFramework\App::main()
             * already sent 200 before callback(), and http_response_code() alone
             * does not override it on php-fpm (https://bugs.php.net/bug.php?id=81451).
             */
            private function getStatusHeader(int $code): string
            {
                return \sprintf('%s %d', $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1', $code);
            }
        };
    }

    /**
     * Get the current user.
     *
     * @throws \OCA\Memories\HttpResponseException if the user is not logged in
     */
    public function getUser(): IUser
    {
        return $this->userSession->getUser()
            ?? throw Exceptions::NotLoggedIn();
    }

    /**
     * Get the current user ID.
     *
     * @throws \OCA\Memories\HttpResponseException if the user is not logged in
     */
    public function getUID(): string
    {
        return $this->getUser()->getUID();
    }

    /**
     * Check if the user is logged in.
     */
    public function isLoggedIn(): bool
    {
        return null !== $this->userSession->getUser();
    }

    /**
     * Get a user's home folder.
     *
     * @param null|string $uid User ID, or null for the user
     *
     * @throws \OCA\Memories\HttpResponseException if the user is not logged in
     */
    public function getUserFolder(?string $uid = null): Folder
    {
        return $this->rootFolder->getUserFolder($uid ?? $this->getUID());
    }
}
