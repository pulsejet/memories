<?php

declare(strict_types=1);

namespace OCA\Memories;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

final class Util extends StaticUtil
{
    public function __construct(
        private LoggerInterface $logger,
        private IUserSession $userSession,
        private IRootFolder $rootFolder,
        private IDBConnection $connection,
        private IURLGenerator $urlGenerator,
        private IRequest $request,
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

    /**
     * Add OG metadata to a page for a node.
     *
     * @param Node   $node        Node to get metadata from
     * @param string $title       Title of the page
     * @param string $url         URL of the page
     * @param array  $previewArgs Preview arguments (e.g. token)
     */
    public function addOgMetadata(Node $node, string $title, string $url, array $previewArgs): void
    {
        // Add title
        \OCP\Util::addHeader('meta', ['property' => 'og:title', 'content' => $title]);

        // Get first node if folder
        if ($node instanceof \OCP\Files\Folder) {
            if (null === ($node = self::getAnyMedia($node))) {
                return; // no media in folder
            }
        }

        // Add file type
        $mimeType = $node->getMimeType();
        if (str_starts_with($mimeType, 'image/')) {
            \OCP\Util::addHeader('meta', ['property' => 'og:type', 'content' => 'image']);
        } elseif (str_starts_with($mimeType, 'video/')) {
            \OCP\Util::addHeader('meta', ['property' => 'og:type', 'content' => 'video']);
        }

        // Add OG url
        \OCP\Util::addHeader('meta', ['property' => 'og:url', 'content' => $url]);

        // Add OG image
        $preview = $this->urlGenerator->linkToRouteAbsolute('memories.Image.preview', array_merge($previewArgs, [
            'id' => $node->getId(),
            'x' => 1024,
            'y' => 1024,
            'a' => true,
        ]));
        \OCP\Util::addHeader('meta', ['property' => 'og:image', 'content' => $preview]);
    }

    /**
     * Run a callback in a transaction.
     * It returns the same type as the return type of the closure.
     *
     * @template T
     *
     * @psalm-param \Closure(): T $callback
     *
     * @psalm-return T
     */
    public function transaction(\Closure $callback): mixed
    {
        $this->connection->beginTransaction();

        try {
            $val = $callback();
            $this->connection->commit();

            return $val;
        } catch (\Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }
    }

    /**
     * Get the version of the native caller.
     */
    public function callerNativeVersion(): ?string
    {
        $userAgent = $this->request->getHeader('User-Agent');

        $matches = [];
        if (preg_match('/MemoriesNative\/([0-9.]+)/', $userAgent, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
