<?php

namespace OCA\Memories\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection
{
    public function __construct(
        private IL10N $l,
        private IURLGenerator $urlGenerator
    ) {}

    /**
     * @return TemplateResponse
     */
    public function getForm()
    {
        $parameters = [
        ];

        return new TemplateResponse('memories', 'admin', $parameters);
    }

    public function getID()
    {
        return 'memories';
    }

    public function getName()
    {
        return $this->l->t('Memories');
    }

    public function getPriority()
    {
        return 75;
    }

    public function getIcon()
    {
        return $this->urlGenerator->imagePath('memories', 'app-dark.svg');
    }
}
