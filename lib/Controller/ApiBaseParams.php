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

namespace OCA\Memories\Controller;

trait ApiBaseParams
{
    /**
     * current request.
     *
     * @var \OCP\IRequest
     */
    protected $request;

    protected function isRecursive()
    {
        return null === $this->request->getParam('folder') || $this->request->getParam('recursive');
    }

    protected function isArchive()
    {
        return null !== $this->request->getParam('archive');
    }

    protected function isMonthView()
    {
        return null !== $this->request->getParam('monthView');
    }

    protected function isReverse()
    {
        return null !== $this->request->getParam('reverse');
    }

    protected function getShareToken()
    {
        return $this->request->getParam('token');
    }
}
