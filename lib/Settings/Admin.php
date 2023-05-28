<?php

namespace OCA\Memories\Settings;

use OCA\Memories\Controller\PageController;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

class Admin implements ISettings
{
    protected $appName;

    public function __construct(string $AppName)
    {
        $this->appName = $AppName;
    }

    /**
     * @return TemplateResponse
     */
    public function getForm()
    {
        \OCP\Util::addScript($this->appName, 'memories-admin');

        return new TemplateResponse('memories', 'main', PageController::getMainParams());
    }

    public function getSection()
    {
        return $this->appName;
    }

    public function getPriority()
    {
        return 50;
    }
}
