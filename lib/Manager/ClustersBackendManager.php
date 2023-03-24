<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2023 Varun Patil <radialapps@gmail.com>
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

namespace OCA\Memories\Manager;

use OCA\Memories\ClustersBackend\Backend;
use OCP\IRequest;

class ClustersBackendManager
{
    /** Mapping of backend name to className */
    public static array $backends = [];

    /**
     * Get a cluster backend.
     *
     * @param string $name Name of the backend
     *
     * @throws \Exception If the backend is not registered
     */
    public static function get(string $name): Backend
    {
        if (!\array_key_exists($name, self::$backends)) {
            throw new \Exception("Invalid clusters backend '{$name}'");
        }

        return \OC::$server->get(self::$backends[$name]);
    }

    /**
     * Register a new backend.
     *
     * @param mixed $name
     * @param mixed $className
     */
    public static function register($name, $className): void
    {
        self::$backends[$name] = $className;
    }

    /**
     * Apply all query transformations for the given request.
     */
    public static function getTransforms(IRequest $request): array
    {
        $transforms = [];
        foreach (array_keys(self::$backends) as $backendName) {
            if ($request->getParam($backendName)) {
                $backend = self::get($backendName);
                if ($backend->isEnabled()) {
                    $transforms[] = [$backend, 'transformDayQuery'];
                }
            }
        }

        return $transforms;
    }

    /**
     * Apply all post-query transformations for the given day object.
     */
    public static function applyDayPostTransforms(IRequest $request, array &$row): void
    {
        foreach (array_keys(self::$backends) as $backendName) {
            if ($request->getParam($backendName)) {
                $backend = self::get($backendName);
                if ($backend->isEnabled()) {
                    $backend->transformDayPost($row);
                }
            }
        }
    }
}
