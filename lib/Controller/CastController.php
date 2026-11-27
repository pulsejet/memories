<?php

declare(strict_types=1);

namespace OCA\Memories\Controller;

use OCA\Memories\AppInfo\Application;
use OCA\Memories\Db\FsManager;
use OCA\Memories\Exceptions;
use OCA\Memories\Service\ServiceManager;
use OCA\Memories\Util;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IPreview;
use OCP\IRequest;
use OCP\Security\ICrypto;

/**
 * Google Cast support.
 *
 * The cast device cannot authenticate, so the sender mints short-lived
 * encrypted per-file tokens that the receiver passes back in the URL.
 */
final class CastController extends ApiController
{
    private const TOKEN_TTL = 12 * 60 * 60;
    private const MAX_FILES = 100;

    public function __construct(
        IRequest $request,
        protected FsManager $fs,
        protected IRootFolder $rootFolder,
        protected IPreview $preview,
        protected ICrypto $crypto,
        protected DownloadController $download,
        protected ServiceManager $serviceManager,
        protected Util $util,
    ) {
        parent::__construct(Application::APPNAME, $request);
    }

    /**
     * Mint cast tokens for files of the current user.
     *
     * @param int[] $files List of file IDs
     */
    #[NoAdminRequired]
    public function tokens(array $files): Http\Response
    {
        return $this->util->guardEx(function () use ($files) {
            $uid = $this->util->getUID();
            $expiry = time() + self::TOKEN_TTL;

            $tokens = [];
            foreach (\array_slice(array_unique(array_map('intval', $files)), 0, self::MAX_FILES) as $fileid) {
                $file = $this->fs->getUserFileOrNull($fileid);
                if (null === $file || !$this->fs->canDownload($file)) {
                    continue;
                }

                $tokens[$fileid] = $this->crypto->encrypt(json_encode([
                    'uid' => $uid,
                    'fileid' => $fileid,
                    'expiry' => $expiry,
                ]) ?: 'failure');
            }

            return new JSONResponse([
                'tokens' => (object) $tokens,
                'expiry' => $expiry,
            ]);
        });
    }

    /**
     * Serve a file to the cast receiver.
     *
     * Images are sent as a 1024px preview, which all cast devices can render quickly.
     * Videos are streamed as-is.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    public function file(int $fileid, string $t): Http\Response
    {
        return $this->util->guardEx(function () use ($fileid, $t) {
            $file = $this->getTokenFile($fileid, $t);

            if (str_starts_with($file->getMimeType(), 'video/')) {
                return $this->download->streamFile($file);
            }

            try {
                $preview = $this->preview->getPreview($file, 1024, 1024, false);
            } catch (\OCP\Files\NotFoundException $e) {
                throw Exceptions::NotFound($e->getMessage());
            }

            $response = new Http\DataDisplayResponse($preview->getContent(), Http::STATUS_OK, [
                'Content-Type' => $preview->getMimeType(),
            ]);
            $response->cacheFor(3600, false, true);

            return $response;
        });
    }

    private function getTokenFile(int $fileid, string $token): File
    {
        try {
            $data = json_decode($this->crypto->decrypt($token), true);
        } catch (\Exception) {
            throw Exceptions::Forbidden('cast token decrypt failed');
        }

        if (!\is_array($data) || (int) ($data['expiry'] ?? 0) < time()) {
            throw Exceptions::Forbidden('cast token expired');
        }

        if ((int) ($data['fileid'] ?? 0) !== $fileid) {
            throw Exceptions::Forbidden('cast token fileid mismatch');
        }

        $file = $this->rootFolder->getUserFolder((string) ($data['uid'] ?? ''))->getFirstNodeById($fileid);
        if ($file instanceof File && $file->isReadable()) {
            return $file;
        }

        // Not in the user's folder, e.g. from an album shared by another user.
        // Access was checked when minting the token, which is bound to this file.
        return $this->serviceManager->getServiceFile($fileid);
    }
}
