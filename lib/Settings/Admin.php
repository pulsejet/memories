<?php

declare(strict_types=1);

namespace OCA\Memories\Settings;

use OCA\Memories\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

final class Admin implements ISettings
{
    public function __construct() {}

    /**
     * @return TemplateResponse
     */
    #[\Override]
    public function getForm()
    {
        \OCP\Util::addScript(Application::APPNAME, 'memories-admin');

        return new TemplateResponse('memories', 'main');
    }

    /**
     * @return string
     */
    #[\Override]
    public function getSection()
    {
        return Application::APPNAME;
    }

    /**
     * @return int
     */
    #[\Override]
    public function getPriority()
    {
        return 50;
    }
}
