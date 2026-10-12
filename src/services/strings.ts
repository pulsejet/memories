import type { RouteLocationNormalized } from 'vue-router';

import { routes } from './router';
import { translate as t } from '@services/l10n';

export function viewName(route: RouteLocationNormalized): string {
  switch (route.name) {
    case routes.Favorites.name:
      return t('memories', 'Favorites');
    case routes.RecognizeList.name:
    case routes.Recognize.name:
    case routes.FaceRecognitionList.name:
    case routes.FaceRecognition.name:
      return t('memories', 'People');
    case routes.Videos.name:
      return t('memories', 'Videos');
    case routes.Panoramas.name:
      return t('memories', 'Panoramas');
    case routes.AlbumsList.name:
    case routes.Albums.name:
      return t('memories', 'Albums');
    case routes.Archive.name:
      return t('memories', 'Archive');
    case routes.ThisDay.name:
      return t('memories', 'On this day');
    case routes.TagsList.name:
    case routes.Tags.name:
      return t('memories', 'Tags');
    case routes.PlacesList.name:
    case routes.Places.name:
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
