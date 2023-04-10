<?php

namespace OCA\Memories\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection
{
    /** @var IL10N */
    private $l;

    /** @var IURLGenerator */
    private $urlGenerator;

    public function __construct(
        IL10N $l,
        IURLGenerator $urlGenerator
    ) {
        $this->l = $l;
        $this->urlGenerator = $urlGenerator;
    }

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
