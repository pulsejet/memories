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
    public function getForm(): TemplateResponse
    {
        return new TemplateResponse('memories', 'admin', []);
    }

    /**
     * @return string
     */
    public function getID(): string
    {
        return 'memories';
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->l->t('Memories');
    }

    /**
     * @return int
     */
    public function getPriority(): int
    {
        return 75;
    }

    /**
     * @return string
     */
    public function getIcon(): string
    {
        return $this->urlGenerator->imagePath('memories', 'app-dark.svg');
    }
}
