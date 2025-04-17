<?php

declare(strict_types=1);

namespace OCA\Memories\Notification;

use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\IAction;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;

class Notifier implements INotifier {
    private $l10nFactory;
    private $url;

    public function __construct(IFactory $l10nFactory, IURLGenerator $url) {
        $this->l10nFactory = $l10nFactory;
        $this->url = $url;
    }

    public function getID(): string {
        return 'memories';
    }

    public function getName(): string {
        return $this->l10nFactory->get('memories')->t('Memories');
    }

    public function prepare(INotification $notification, string $languageCode): INotification {
        if ($notification->getApp() !== 'memories') {
            throw new \InvalidArgumentException('Notification not from Memories app');
        }

        $l = $this->l10nFactory->get('memories', $languageCode);

        switch ($notification->getSubject()) {
            case 'new_trip':
                $params = $notification->getSubjectParameters();
                $tripName = $params['tripName'];
                $photoCount = $params['photoCount'];

                $notification->setIcon($this->url->getAbsoluteURL($this->url->imagePath('memories', 'app-dark.svg')));
                $notification->setParsedSubject($l->t('New trip detected: %s', [$tripName]));
                $notification->setParsedMessage($l->t('A new trip with %s photos was detected and added to your Memories.', [$photoCount]));

                foreach ($notification->getActions() as $action) {
                    $this->processAction($action, $l);
                    $notification->addParsedAction($action);
                }
                return $notification;

            case 'new_trips':
                $params = $notification->getSubjectParameters();
                $tripCount = $params['tripCount'];
                $photoCount = $params['photoCount'];
                
                $notification->setIcon($this->url->getAbsoluteURL($this->url->imagePath('memories', 'app-dark.svg')));
                $notification->setParsedSubject($l->t('%s new trips detected', [$tripCount]));
                $notification->setParsedMessage(
                    $l->t('Found %s new trips with a total of %s photos in your Memories.', [$tripCount, $photoCount])
                );

                foreach ($notification->getActions() as $action) {
                    $this->processAction($action, $l);
                    $notification->addParsedAction($action);
                }
                return $notification;
                
            default:
                throw new \InvalidArgumentException('Unknown subject: ' . $notification->getSubject());
        }
    }

    private function processAction(IAction $action, $l) {
        switch ($action->getLabel()) {
            case 'view':
                $action->setParsedLabel($l->t('View trips'));
                return;
            default:
                throw new \InvalidArgumentException('Unknown action: ' . $action->getLabel());
        }
    }
}
