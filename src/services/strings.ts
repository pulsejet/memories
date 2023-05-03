import staticConfig from './static-config';
import { translate as t } from '@nextcloud/l10n';

type RouteNameType = string | null | undefined;

export function emptyDescription(routeName: RouteNameType): string {
  switch (routeName) {
    case 'timeline':
      return t('memories', 'Upload some photos and make sure the timeline path is configured');
    case 'favorites':
      return t('memories', 'Mark photos as favorite to find them easily');
    case 'thisday':
      return t('memories', 'Memories from past years will appear here');
    case 'facerecognition':
      return staticConfig.getSync('facerecognition_enabled')
        ? t('memories', 'You will find your friends soon. Please be patient')
        : t('memories', 'Face Recognition is disabled. Enable in settings to find your friends');
    case 'videos':
      return t('memories', 'Your videos will appear here');
    case 'albums':
      return vueroute().params.name
        ? t('memories', 'No photos in this album yet')
        : t('memories', 'Create an album to get started');
    case 'archive':
      return t('memories', "Archive photos you don't want to see in your timeline");
    case 'tags':
      return t('memories', 'Tag photos to find them easily');
    case 'recognize':
      return t('memories', 'Recognize is still working on your photos');
    case 'places':
      return t('memories', 'Places you have been to will appear here');
    default:
      return '';
  }
}

export function viewName(routeName: RouteNameType): string {
  switch (routeName) {
    case 'timeline':
      return t('memories', 'Your Timeline');
    case 'favorites':
      return t('memories', 'Favorites');
    case 'recognize':
    case 'facerecognition':
      return t('memories', 'People');
    case 'videos':
      return t('memories', 'Videos');
    case 'albums':
      return t('memories', 'Albums');
    case 'archive':
      return t('memories', 'Archive');
    case 'thisday':
      return t('memories', 'On this day');
    case 'tags':
      return t('memories', 'Tags');
    case 'places':
      return t('memories', 'Places');
    default:
      return '';
  }
}
