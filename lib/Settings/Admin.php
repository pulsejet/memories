<?php

declare(strict_types=1);

namespace OCA\Memories\Settings;

use OCA\Memories\AppInfo\Application;
use OCA\Memories\Controller\PageController;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

class Admin implements ISettings
{
    public function __construct() {}

    /**
     * @return TemplateResponse
     */
    public function getForm(): TemplateResponse
    {
        \OCP\Util::addScript(Application::APPNAME, 'memories-admin');

        return new TemplateResponse('memories', 'main', PageController::getMainParams());
    }

    /**
     * @return string
     */
    public function getSection(): string
    {
        return Application::APPNAME;
    }

    /**
     * @return int
     */
    public function getPriority(): int
    {
        return 50;
    }
}
