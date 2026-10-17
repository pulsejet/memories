<?php

declare(strict_types=1);

namespace OCA\Memories\Controller;

use OCA\Memories\AppInfo\Application;
use OCA\Memories\Exceptions;
use OCA\Memories\Settings\SystemConfig;
use OCA\Memories\Util;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\FileInfo;
use OCP\IRequest;

final class TrashController extends ApiController
{
    public function __construct(
        IRequest $request,
        protected ?\OCA\Files_Trashbin\Trash\ITrashManager $trashManager,
        protected SystemConfig $systemConfig,
        protected Util $util,
    ) {
        parent::__construct(Application::APPNAME, $request);
    }

    /**
     * List trashed photos and videos as flat hits.
     */
    #[NoAdminRequired]
    public function list(): Http\Response
    {
        return $this->util->guardEx(function () {
            if (null === $this->trashManager) {
                throw Exceptions::NotEnabled('Trash');
            }

            $user = $this->util->getUser();
            $prefix = "/trashbin/{$user->getUID()}/trash";
            $scopes = $this->getScopes($user->getUID());

            $out = [];
            $queue = [];
            foreach ($this->trashManager->listTrashRoot($user) as $item) {
                $davName = \OCA\Files_Trashbin\Trashbin::getTrashFilename(
                    $item->getName(),
                    $item->getDeletedTime(),
                );
                $queue[] = [$item, "{$prefix}/{$davName}"];
            }

            $seen = 0;
            while ([] !== $queue) {
                [$item, $davPath] = array_pop($queue);
                if (++$seen > 100000) {
                    break;
                }

                if (!$this->isInScope($item, $scopes)) {
                    continue;
                }

                if (FileInfo::TYPE_FOLDER === $item->getType()) {
                    try {
                        foreach ($this->trashManager->listTrashFolder($item) as $child) {
                            $queue[] = [$child, "{$davPath}/{$child->getName()}"];
                        }
                    } catch (\Throwable) {
                        // Unreadable folder, skip it
                    }

                    continue;
                }

                $mime = $item->getMimetype();
                if (!str_starts_with($mime, 'image/') && !str_starts_with($mime, 'video/')) {
                    continue;
                }

                $id = (int) $item->getId();
                if ($id <= 0) {
                    continue;
                }

                $orig = $item->getOriginalLocation();
                $out[] = [
                    'fileid' => $id,
                    'deletion_time' => $item->getDeletedTime(),
                    'basename' => '' === $orig ? $item->getName() : basename($orig),
                    'mimetype' => $mime,
                    'size' => $item->getSize(),
                    'etag' => $item->getEtag(),
                    'trash_path' => $davPath,
                    'orig_path' => $orig,
                ];
            }

            return new JSONResponse($out, Http::STATUS_OK);
        });
    }

    /**
     * Get timeline and folders paths to show trashed files from.
     *
     * @return string[] List of paths, may match everything on failure
     */
    private function getScopes(string $uid): array
    {
        try {
            $scopes = $this->systemConfig->getTimelinePaths($uid);
            $foldersPath = $this->systemConfig->getUserConfigValue('foldersPath', '/');
            $scopes[] = Util::sanitizePath("/{$foldersPath}") ?? '/';
        } catch (\Throwable) {
            $scopes = ['/'];
        }

        return $scopes;
    }

    /**
     * Check if a trash item belongs to one of the scopes.
     * Non-legacy items are always included since their original
     * location cannot be matched against user paths.
     *
     * @param string[] $scopes Paths to include
     */
    private function isInScope(
        \OCA\Files_Trashbin\Trash\ITrashItem $item,
        array $scopes,
    ): bool {
        if (!$item->getTrashBackend() instanceof \OCA\Files_Trashbin\Trash\LegacyTrashBackend) {
            return true;
        }

        $orig = $item->getOriginalLocation();
        if ('' === $orig || Util::matchesAnyPrefix($orig, $scopes)) {
            return true;
        }

        if (FileInfo::TYPE_FOLDER !== $item->getType()) {
            return false;
        }

        $normOrig = trim($orig, '/');
        foreach ($scopes as $scope) {
            if (trim($scope, '/') === $normOrig || Util::matchesAnyPrefix($scope, [$orig])) {
                return true;
            }
        }

        return false;
    }
}
