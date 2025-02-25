import staticConfig from './static-config';
import { translate as t } from '@services/l10n';

export function emptyDescription(routeName: string): string {
  switch (routeName) {
    case _m.routes.Base.name:
      return t('memories', 'Upload some photos and make sure the timeline path is configured');
    case _m.routes.Favorites.name:
      return t('memories', 'Mark photos as favorite to find them easily');
    case _m.routes.ThisDay.name:
      return t('memories', 'Memories from past years will appear here');
    case _m.routes.Recognize.name:
      return t('memories', 'Recognize is still working on your photos');
    case _m.routes.FaceRecognition.name:
      return staticConfig.getSync('facerecognition_enabled')
        ? t('memories', 'You will find your friends soon. Please be patient')
        : t('memories', 'Face Recognition is disabled. Enable in settings to find your friends');
    case _m.routes.Videos.name:
      return t('memories', 'Your videos will appear here');
    case _m.routes.Albums.name:
      return _m.route.params.name
        ? t('memories', 'Add photos to albums by selecting them on your timeline.')
        : t('memories', 'Create an album to get started');
    case _m.routes.Archive.name:
      return t('memories', "Archive photos you don't want to see in your timeline");
    case _m.routes.Tags.name:
      return t('memories', 'Tag photos to find them easily');
    case _m.routes.Places.name:
      return t('memories', 'Places you have been to will appear here');
    default:
      return String();
  }
}

export function viewName(routeName: string): string {
  switch (routeName) {
    case _m.routes.Favorites.name:
      return t('memories', 'Favorites');
    case _m.routes.Recognize.name:
    case _m.routes.FaceRecognition.name:
      return t('memories', 'People');
    case _m.routes.Videos.name:
      return t('memories', 'Videos');
    case _m.routes.LivePhotos.name:
      return t('memories', 'Live photos');
    case _m.routes.Panoramas.name:
      return t('memories', 'Panoramas');
    case _m.routes.Albums.name:
      return t('memories', 'Albums');
    case _m.routes.Archive.name:
      return t('memories', 'Archive');
    case _m.routes.ThisDay.name:
      return t('memories', 'On this day');
    case _m.routes.Tags.name:
      return t('memories', 'Tags');
    case _m.routes.Places.name:
      return t('memories', 'Places');
    default:
      return String();
  }
}

export function albumDisplayName(name: string): string {
  if (name.startsWith('.link-')) {
    return t('memories', 'Shared Link');
  }
  return name;
}
