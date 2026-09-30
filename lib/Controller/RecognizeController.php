<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2022 Varun Patil <radialapps@gmail.com>
 * @author Varun Patil <radialapps@gmail.com>
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\Memories\Controller;

use OCA\Memories\AppInfo\Application;
use OCA\Memories\Exceptions;
use OCA\Memories\Settings\SystemConfig;
use OCA\Memories\Util;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

final class RecognizeController extends ApiController
{
    public function __construct(
        IRequest $request,
        protected SystemConfig $systemConfig,
        protected Util $util,
        protected ?\OCA\Recognize\Public\ApiKeyManager $recognizeApiKeyManager,
    ) {
        parent::__construct(Application::APPNAME, $request);
    }

    #[NoAdminRequired]
    public function apiKey(): Http\Response
    {
        return $this->util->guardEx(function () {
            if (!$this->systemConfig->recognizeIsEnabled()) {
                throw Exceptions::NotEnabled('Recognize');
            }

            try {
                $key = $this->recognizeApiKeyManager?->generateApiKey();
                if (null === $key || '' === $key) {
                    throw new \Exception('Empty API key returned');
                }
            } catch (\Exception $e) {
                throw Exceptions::Generic($e);
            }

            return new JSONResponse(['apiKey' => $key], Http::STATUS_OK);
        });
    }
}
