<?php

declare(strict_types=1);

namespace OCA\Memories\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection
{
    public function __construct(
        private IL10N $l,
        private IURLGenerator $urlGenerator,
    ) {}

    /**
     * @return TemplateResponse
     */
    public function getForm()
    {
        return new TemplateResponse('memories', 'admin', []);
    }

    /**
     * @return string
     */
    #[\Override]
    public function getID()
    {
        return 'memories';
    }

    /**
     * @return string
     */
    #[\Override]
    public function getName()
    {
        return $this->l->t('Memories');
    }

    /**
     * @return int
     */
    #[\Override]
    public function getPriority()
    {
        return 75;
    }

    /**
     * @return string
     */
    #[\Override]
    public function getIcon()
    {
        return $this->urlGenerator->imagePath('memories', 'app-dark.svg');
    }
}
