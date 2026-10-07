<template>
  <Teleport to="body" :disabled="!windowDims.isMobile">
    <div v-if="show" class="memories-top-bar top-bar">
      <NcActions :inline="1">
        <NcActionButton :aria-label="t('memories', 'Cancel')" @click="clear()">
          {{ t('memories', 'Cancel') }}
          <template #icon> <CloseIcon :size="20" /> </template>
        </NcActionButton>
      </NcActions>

      <div class="text">
        {{ n('memories', '{n} selected', '{n} selected', size, { n: size }) }}
      </div>

      <NcActions :inline="3">
        <NcActionButton
          v-for="action of getActions()"
          :key="action.name"
          :aria-label="action.name"
          :disabled="!!loading"
          close-after-click
          @click="click(action)"
        >
          {{ action.name }}
          <template #icon>
            <component :is="action.icon" :size="20" />
          </template>
        </NcActionButton>
      </NcActions>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import { ref, watch, onMounted, onBeforeUnmount, markRaw } from 'vue';
import { useRoute } from 'vue-router';

import { showError } from '@nextcloud/dialogs';

import NcActions from '@nextcloud/vue/components/NcActions';
import NcActionButton from '@nextcloud/vue/components/NcActionButton';

import { config } from '@services/user-config';
import { windowDims } from '@services/viewport';
import { routeIs } from '@services/router';

import { t, n } from '@services/l10n';
import { constants } from '@services/constants';
import initstate from '@services/init-state';
import * as dav from '@services/dav';
import * as utils from '@services/utils/common';
import * as nativex from '@native';

import ShareIcon from 'vue-material-design-icons/ShareVariant.vue';
import StarIcon from 'vue-material-design-icons/Star.vue';
import DownloadIcon from 'vue-material-design-icons/Download.vue';
import UploadIcon from 'vue-material-design-icons/CloudUpload.vue';
import DeleteIcon from 'vue-material-design-icons/TrashCanOutline.vue';
import EditFileIcon from 'vue-material-design-icons/FileEdit.vue';
import ArchiveIcon from 'vue-material-design-icons/PackageDown.vue';
import UnarchiveIcon from 'vue-material-design-icons/PackageUp.vue';
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue';
import CloseIcon from 'vue-material-design-icons/Close.vue';
import MoveIcon from 'vue-material-design-icons/ImageMove.vue';
import AlbumsIcon from 'vue-material-design-icons/ImageAlbum.vue';
import AlbumRemoveIcon from 'vue-material-design-icons/BookRemove.vue';
import FolderMoveIcon from 'vue-material-design-icons/FolderMove.vue';
import RotateLeftIcon from 'vue-material-design-icons/RotateLeft.vue';
import ImageCheckIcon from 'vue-material-design-icons/ImageCheck.vue';
import RefreshIcon from 'vue-material-design-icons/Refresh.vue';

import type { IDay, IHeadRow, IPhoto, IRow, IUploadNativeX } from '@typings';
import type ScrollerManager from './ScrollerManager.vue';

/**
 * The distance for which the touch selection is clamped.
 * The x value is absolute from the top, y value is absolute from the bottom.
 */
const TOUCH_SELECT_CLAMP = {
  top: 110, // min top for scrolling
  bottom: 110, // min bottom for scrolling
  maxDelta: 10, // max speed of touch scroll
  bufferPx: 5, // number of pixels to clamp inside recycler area
};

class Selection extends Map<string, IPhoto> {
  addBy(photo: IPhoto): this {
    console.assert(!!photo?.key, 'SelectionManager::addBy encountered a photo without a key');
    this.set(photo.key!, photo);
    return this;
  }

  getBy({ key }: { key?: string }): IPhoto | undefined {
    console.assert(!!key, 'SelectionManager::getBy encountered a photo without a key');
    return this.get(key!);
  }

  deleteBy({ key }: { key?: string }): boolean {
    console.assert(!!key, 'SelectionManager::deleteBy encountered a photo without a key');
    return this.delete(key!);
  }

  hasBy({ key }: { key?: string }): boolean {
    console.assert(!!key, 'SelectionManager::hasBy encountered a photo without a key');
    return this.has(key!);
  }

  fileids(): Set<number> {
    return new Set(Array.from(this.values()).map((p) => p.fileid));
  }

  photosNoDupFileId(): IPhoto[] {
    const fileids = this.fileids();
    return Array.from(this.values()).filter((p) => fileids.delete(p.fileid));
  }

  photosFromFileIds(fileIds: number[] | Set<number>): IPhoto[] {
    const idSet = new Set(fileIds);
    const photos = Array.from(this.values());
    return photos.filter((p) => idSet.has(p?.fileid));
  }

  clone(): Selection {
    return new Selection(this);
  }
}

type ISelectionAction = {
  /** Identifier (optional) */
  id?: string;
  /** Display text */
  name: string;
  /** Icon component */
  icon: any;
  /** Action to perform */
  callback: (selection: Selection) => Promise<void>;
  /** Condition to check for including */
  if?: (self?: any) => boolean;
  /** Allow for public routes (default false) */
  allowPublic?: boolean;
};

const props = defineProps<{
  heads: Map<number, IHeadRow>;
  /** List of rows for multi selection */
  rows: IRow[];
  /** Rows are in ascending order (desc is normal) */
  isreverse: boolean;
  /** Recycler element to scroll during touch multi-select */
  recycler?: HTMLDivElement | null;
  /** Scroller manager associated with the timeline */
  scrollerManager?: InstanceType<typeof ScrollerManager> | null;
}>();

const emit = defineEmits<{
  updateLoading: [delta: number];
}>();

const route = useRoute();
const show = ref(false);
const size = ref(0);
const loading = ref(0);
const selection = ref(new Selection());

const touchAnchor = ref<IPhoto | null>(null);
const prevTouch = ref<Touch | null>(null);
const touchTimer = ref(0);
const touchMoved = ref(false);
const touchPrevSel = ref<Selection | null>(null);
const prevOver = ref<IPhoto | null>(null);
const touchScrollInterval = ref(0);
const touchScrollDelta = ref(0);
const touchMoveSelFrame = ref(0);
const multiSelectDelta = ref<1 | -1 | null>(null);

const defaultActions: ISelectionAction[] = [
  {
    name: t('memories', 'Upload Local'),
    icon: markRaw(UploadIcon),
    callback: uploadLocalSelection,
    if: () => nativex.has() && Array.from(selection.value.values()).some((p) => utils.isLocalPhoto(p)),
  },
  {
    name: t('memories', 'Delete'),
    icon: markRaw(DeleteIcon),
    callback: deleteSelection,
    allowPublic: true,
    if: () => !routeIs.Albums && (!routeIs.Public || initstate.allow_delete),
  },
  {
    name: t('memories', 'Remove from album'),
    icon: markRaw(AlbumRemoveIcon),
    callback: deleteSelection,
    if: () => routeIs.Albums,
  },
  {
    name: t('memories', 'Share'),
    icon: markRaw(ShareIcon),
    callback: shareSelection,
    if: () => !routeIs.Albums,
  },
  {
    name: t('memories', 'Download'),
    icon: markRaw(DownloadIcon),
    callback: downloadSelection,
    allowPublic: true,
    if: () => !initstate.noDownload,
  },
  {
    name: t('memories', 'Favorite'),
    icon: markRaw(StarIcon),
    callback: favoriteSelection,
  },
  {
    name: t('memories', 'Archive'),
    icon: markRaw(ArchiveIcon),
    callback: archiveSelection,
    if: () => !routeIsArchiveFolder() && !routeIs.Albums,
  },
  {
    name: t('memories', 'Unarchive'),
    icon: markRaw(UnarchiveIcon),
    callback: archiveSelection,
    if: () => routeIsArchiveFolder(),
  },
  {
    name: t('memories', 'Edit metadata'),
    icon: markRaw(EditFileIcon),
    callback: editMetadataSelection,
  },
  {
    name: t('memories', 'Rotate / Flip'),
    icon: markRaw(RotateLeftIcon),
    callback: () => editMetadataSelection(selection.value, [5]),
  },
  {
    name: t('memories', 'Refresh metadata'),
    icon: markRaw(RefreshIcon),
    callback: reindexSelection,
    if: () => Array.from(selection.value.values()).some((p) => !utils.isLocalPhoto(p)),
  },
  {
    name: t('memories', 'View in folder'),
    icon: markRaw(OpenInNewIcon),
    callback: viewInFolder,
    if: () => selection.value.size === 1 && !routeIs.Albums,
  },
  {
    name: t('memories', 'Set as cover image'),
    icon: markRaw(ImageCheckIcon),
    callback: setClusterCover,
    if: () => selection.value.size === 1 && routeIs.Cluster && !routeIs.RecognizeUnassigned,
  },
  {
    name: t('memories', 'Move to folder'),
    icon: markRaw(FolderMoveIcon),
    callback: moveToFolder,
    if: () => !routeIs.Albums && !routeIsArchiveFolder(),
  },
  {
    name: t('memories', 'Add to album'),
    icon: markRaw(AlbumsIcon),
    callback: addToAlbum,
    if: () => config.albums_enabled && !routeIs.Albums,
  },
  {
    id: 'face-move',
    name: t('memories', 'Move to person'),
    icon: markRaw(MoveIcon),
    callback: moveSelectionToPerson,
    if: () => routeIs.Recognize,
  },
  {
    name: t('memories', 'Remove from person'),
    icon: markRaw(CloseIcon),
    callback: removeSelectionFromPerson,
    if: () => routeIs.Recognize && !routeIs.RecognizeUnassigned,
  },
];

// Move face-move to start if unassigned faces
if (routeIs.RecognizeUnassigned) {
  const i = defaultActions.findIndex((a) => a.id === 'face-move');
  defaultActions.unshift(defaultActions.splice(i, 1)[0]);
}

onMounted(() => {
  // Subscribe to global events
  utils.bus.on('memories:albums:update', clear);
  utils.bus.on('memories:fragment:pop:selection', clear);
});

onBeforeUnmount(() => {
  // Unsubscribe from global events
  utils.bus.off('memories:albums:update', clear);
  utils.bus.off('memories:fragment:pop:selection', clear);
});

watch(show, (value) => {
  utils.fragment.if(value, utils.fragment.types.selection);
});

function deleteSelectedPhotosById(delIds: number[], sel: Selection) {
  utils.bus.emit('memories:timeline:deleted', sel.photosFromFileIds(delIds));
}

function updateLoading(delta: number) {
  loading.value += delta; // local (disable buttons)
  emit('updateLoading', delta); // timeline (loading icon)
}

/** Is archive route */
function routeIsArchiveFolder(): boolean {
  // Check if the route itself is archive
  if (routeIs.Archive) return true;

  // Check if route is folder and the path contains .archive
  if (routeIs.Folders) {
    let path = route.params.path || '';
    if (Array.isArray(path)) path = path.join('/');
    return ('/' + path + '/').includes('/.archive/');
  }

  return false;
}

/** Trigger to update props from selection set */
function selectionChanged() {
  show.value = selection.value.size > 0;
  size.value = selection.value.size;
}

/** Is the selection empty */
function empty(): boolean {
  return !selection.value.size;
}

/** Get the actions list */
function getActions(): ISelectionAction[] {
  return defaultActions.filter((a) => (!a.if || a.if()) && (!routeIs.Public || a.allowPublic));
}

/** Click on an action */
async function click(action: ISelectionAction) {
  try {
    updateLoading(1);
    await action.callback(selection.value);
  } catch (error) {
    console.error(error);
  } finally {
    updateLoading(-1);
  }
}

/** Clicking on photo */
function clickPhoto(photo: IPhoto, event: PointerEvent | null, rowIdx: number) {
  if (photo.flag & constants.FLAG_PLACEHOLDER) return;
  if (event?.pointerType === 'touch') return; // let touch events handle this
  if (event?.pointerType === 'mouse' && event?.button !== 0) return; // only left click for mouse

  if (!empty() || event?.ctrlKey || event?.shiftKey) {
    clickSelectionIcon(photo, event, rowIdx);
  } else {
    openViewer(photo);
  }
}

/** Clicking on checkmark icon */
function clickSelectionIcon(photo: IPhoto, event: PointerEvent | null, rowIdx: number) {
  if (!empty() && event?.shiftKey) {
    selectMulti(photo, props.rows, rowIdx);
  } else {
    selectPhoto(photo);
  }
}

/** Tap on */
function touchstartPhoto(photo: IPhoto, event: TouchEvent, rowIdx: number) {
  if (photo.flag & constants.FLAG_PLACEHOLDER) return;

  // Note that the usage of touch events over pointer events is deliberate.
  //
  // https://developer.mozilla.org/en-US/docs/Web/API/Element/pointermove_event
  // > The pointermove event is fired when a pointer changes coordinates,
  // > and the pointer has not been canceled by a browser touch-action.
  //
  // On scroll, pointer events get cancelled immediately, and setting
  // CSS `touch-action: none` breaks native scrolling.

  // Bail if the user was scrolling the recycler recently
  // https://github.com/pulsejet/memories/issues/1066
  if (props.scrollerManager?.scrollingRecyclerNowTimer.pending) return;

  // Prevent this element from being removed from the DOM
  // If it gets removed then subsequent touch events are not triggered
  props.rows[rowIdx].virtualSticky = true;

  resetTouchParams();

  touchAnchor.value = photo;
  prevOver.value = photo;
  prevTouch.value = event.touches[0];
  touchPrevSel.value = selection.value.clone();
  touchMoved.value = false;
  touchTimer.value = window.setTimeout(() => {
    if (touchAnchor.value === photo) {
      selectPhoto(photo, true);
    }
    touchTimer.value = 0;
  }, 600);
}

/** Tap off */
function touchendPhoto(photo: IPhoto, event: TouchEvent, rowIdx: number) {
  if (photo.flag & constants.FLAG_PLACEHOLDER) return;
  delete props.rows[rowIdx].virtualSticky;

  if (touchTimer.value && !touchMoved.value) {
    // Register a single tap, only if the touch hadn't moved at all
    clickPhoto(photo, null, rowIdx);
  }

  resetTouchParams();
}

function resetTouchParams() {
  touchAnchor.value = null;
  touchMoved.value = false;
  prevOver.value = null;

  window.clearTimeout(touchTimer.value);
  touchTimer.value = 0;

  window.cancelAnimationFrame(touchScrollInterval.value);
  touchScrollInterval.value = 0;

  window.cancelAnimationFrame(touchMoveSelFrame.value);
  touchMoveSelFrame.value = 0;

  prevTouch.value = null;
}

/**
 * Tap over
 * photo and rowIdx are that of the *anchor*
 */
function touchmovePhoto(anchor: IPhoto, event: TouchEvent, rowIdx: number) {
  if (anchor.flag & constants.FLAG_PLACEHOLDER) return;

  // Use first touch -- can't do much
  const touch: Touch = event.touches[0];

  if (touchTimer.value) {
    // Regardless of whether we continue to run the timer,
    // we still need to mark that the touch had moved.
    // This is so that we can disregard the event if only
    // registering a tap event (not a long press).
    // https://github.com/pulsejet/memories/issues/516
    touchMoved.value = true;

    // To be more forgiving, check if touch is still
    // within 30px of anchor touch (prevTouch)
    if (
      prevTouch.value &&
      Math.abs(prevTouch.value.clientX - touch.clientX) < 30 &&
      Math.abs(prevTouch.value.clientY - touch.clientY) < 30
    ) {
      return;
    }

    // Touch is not held, just cancel
    window.clearTimeout(touchTimer.value);
    touchTimer.value = 0;
    touchAnchor.value = null;
    return;
  } else if (!touchAnchor.value) {
    // Touch was previously cancelled
    return;
  }

  // Prevent scrolling
  event.preventDefault();
  event.stopPropagation();

  // This should never happen
  if (!touch) return;
  prevTouch.value = touch;

  // Scroll if at top or bottom
  const scrollUp = touch.clientY < TOUCH_SELECT_CLAMP.top;
  const scrollDown = touch.clientY > windowDims.height - TOUCH_SELECT_CLAMP.bottom;
  if (scrollUp || scrollDown) {
    if (scrollUp) {
      touchScrollDelta.value = Math.max((touch.clientY - TOUCH_SELECT_CLAMP.top) / 3, -TOUCH_SELECT_CLAMP.maxDelta);
    } else {
      touchScrollDelta.value = Math.min(
        (touch.clientY - windowDims.height + TOUCH_SELECT_CLAMP.bottom) / 3,
        TOUCH_SELECT_CLAMP.maxDelta,
      );
    }

    if (touchAnchor.value && !touchScrollInterval.value) {
      let frameCount = 3;

      const fun = () => {
        if (!prevTouch.value) return;
        props.recycler!.scrollTop += touchScrollDelta.value;

        if (frameCount++ >= 3) {
          touchMoveSelect(prevTouch.value, rowIdx);
          frameCount = 0;
        }

        if (touchScrollInterval.value) {
          touchScrollInterval.value = window.requestAnimationFrame(fun);
        }
      };
      touchScrollInterval.value = window.requestAnimationFrame(fun);
    }
  } else if (touchScrollInterval.value) {
    window.cancelAnimationFrame(touchScrollInterval.value);
    touchScrollInterval.value = 0;
  }

  // Trigger multiselection update in next frame
  // If there's no anchor then this is pointless cause it gets cancelled
  // If already scrolling up/down then the effect will be called anyway
  if (touchAnchor.value && !touchScrollInterval.value && !touchMoveSelFrame.value) {
    touchMoveSelFrame.value = window.requestAnimationFrame(() => {
      touchMoveSelFrame.value = 0;
      touchMoveSelect(touch, rowIdx);
    });
  }
}

/** Multi-select triggered by touchmove */
function touchMoveSelect(touch: Touch, rowIdx: number) {
  // Assertions
  if (!touchAnchor.value) return;

  // Clamp the Y value to lie inside the recycler area
  const recyclerRect = props.recycler?.getBoundingClientRect();
  const clampedY = Math.max(
    (recyclerRect?.top ?? 0) + TOUCH_SELECT_CLAMP.bufferPx,
    Math.min((recyclerRect?.bottom ?? 0) - TOUCH_SELECT_CLAMP.bufferPx, touch.clientY),
  );

  // Which photo is the cursor over, if any
  const elem: any = document
    .elementsFromPoint(touch.clientX, clampedY)
    .find((e) => e.classList.contains('p-outer-super'));
  let overPhoto: IPhoto | null = elem?.__photo;
  if ((overPhoto?.flag ?? 0) & constants.FLAG_PLACEHOLDER) overPhoto = null;

  // Do multi-selection "till" overPhoto "from" anchor
  // This logic is completely different from the desktop because of the
  // existence of a definitive "anchor" element. We just need to find
  // everything between the anchor and the current photo
  if (overPhoto && prevOver.value !== overPhoto) {
    prevOver.value = overPhoto;

    // days reverse XOR rows reverse
    let reverse: boolean;
    if (overPhoto.dayid === touchAnchor.value.dayid) {
      const l = overPhoto?.d?.detail;
      if (!l) return; // Shouldn't happen
      const ai = l.indexOf(touchAnchor.value);
      const oi = l.indexOf(overPhoto);
      if (ai === -1 || oi === -1) return; // Shouldn't happen
      reverse = ai > oi;
    } else {
      reverse = overPhoto.dayid > touchAnchor.value.dayid != props.isreverse;
    }

    const newSelection = touchPrevSel.value!.clone();
    const updatedDays = new Set<number>();

    // Walk over rows
    let i = rowIdx;
    let j = props.rows[i].photos?.indexOf(touchAnchor.value) ?? -2;
    if (j === -2) return; // row is not initialized yet?!
    while (true) {
      if (j < 0) {
        while (i > 0 && !props.rows[--i].photos);
        const plen = props.rows[i].photos?.length;
        if (!plen) break;
        j = plen - 1;
        continue;
      } else if (j >= props.rows[i].photos!.length) {
        while (i < props.rows.length - 1 && !props.rows[++i].photos);
        if (!props.rows[i].photos) break;
        j = 0;
        continue;
      }

      const photo = props.rows[i]?.photos?.[j];
      if (!photo) break; // shouldn't happen, ever

      // This is there now
      newSelection.addBy(photo);

      // Perf: only update heads if not selected
      if (!(photo.flag & constants.FLAG_SELECTED)) {
        selectPhoto(photo, true, true);
        updatedDays.add(photo.dayid);
      }

      // We're trying to update too much -- something went wrong
      if (newSelection.size - selection.value.size > 50) break;

      // Check goal
      if (photo === overPhoto) break;
      j += reverse ? -1 : 1;
    }

    // Remove unselected
    for (const [_, photo] of selection.value) {
      if (!newSelection.hasBy(photo)) {
        selectPhoto(photo, false, true);
        updatedDays.add(photo.dayid);
      }
    }

    // Update heads
    for (const dayid of updatedDays) {
      updateHeadSelected(props.heads.get(dayid)!);
    }
  }
}

/** Add a photo to selection list */
function selectPhoto(photo: IPhoto, val?: boolean, noUpdate?: boolean) {
  if (photo.flag & constants.FLAG_PLACEHOLDER) {
    return; // ignore placeholders
  }

  const nval = val ?? !selection.value.hasBy(photo);
  if (nval) {
    photo.flag |= constants.FLAG_SELECTED;
    selection.value.addBy(photo);
    selectionChanged();
  } else {
    photo.flag &= ~constants.FLAG_SELECTED;
    selection.value.deleteBy(photo);
    selectionChanged();
  }

  if (!noUpdate) {
    updateHeadSelected(props.heads.get(photo.dayid)!);
  }
}

/** Multi-select */
function selectMulti(photo: IPhoto, rows: IRow[], rowIdx: number) {
  const pRow = rows[rowIdx];
  const pIdx = pRow.photos?.indexOf(photo) ?? -1;
  if (pIdx === -1) return;

  // Set of dayIds to update at the end of this method
  const touchedDays = new Set<number>();

  /**
   * @brief Look behind/ahead for a selected photo.
   * @param delta -1 for behind, 1 for ahead
   * @returns the list of photos behind/ahead the current photo upto
   * the first selected photo. Null if selected photo not found.
   */
  const look = (delta: 1 | -1): IPhoto[] | undefined => {
    const result: IPhoto[] = [];

    // Iterate all rows behind or ahead of this row
    // A maximum of 1000 rows are checked in either direction
    const i_s = rowIdx; // i-start
    const i_e = delta < 0 ? Math.max(rowIdx - 1000, 0) : Math.min(rowIdx + 1000, rows.length); // i-end
    for (let i = i_s; delta < 0 ? i >= i_e : i < i_e; i += delta) {
      const row = rows[i];
      if (row.type !== 1) continue; // skip non-photo rows

      // Give up if unloaded rows are found
      if (!row.photos?.length) break;

      // Iterate photos in this row from the end
      const j_s = i === rowIdx ? pIdx : delta < 0 ? row.photos.length - 1 : 0; // j-start
      const j_e = delta < 0 ? 0 : row.photos.length; // j-end
      for (let j = j_s; delta < 0 ? j >= j_e : j < j_e; j += delta) {
        const p = row.photos[j];
        if (p.flag & constants.FLAG_PLACEHOLDER || !p.fileid) continue;

        if (p.flag & constants.FLAG_SELECTED) {
          // Found a selected photo, return everything excluding this
          return result;
        }

        result.push(p);
      }
    }
  };

  /**@brief  Select or de-select a photo and update touchedDays */
  const selfun = (p: IPhoto, val: boolean) => {
    selectPhoto(p, val, true);
    touchedDays.add(p.dayid);
  };
  const select = (p: IPhoto) => selfun(p, true);
  const deselect = (p: IPhoto) => selfun(p, false);

  /**
   * @brief Backtrack and select photos behind/ahead.
   * @param delta -1 for behind, 1 for ahead
   * @returns true if did some actions
   */
  const backtrack = (delta: 1 | -1): boolean => {
    const list = look(delta);
    if (!list) return false;

    // Clear everything in front in this day
    const detail = photo.d!.detail!;
    const i_s = detail.indexOf(photo) - delta; // i-start
    const i_e = delta < 0 ? detail.length : 0; // i-end
    for (let i = i_s; delta < 0 ? i < i_e : i >= i_e; i -= delta) {
      if (detail[i].flag & constants.FLAG_SELECTED) {
        deselect(detail[i]);
      }
    }

    // De-select everything else in front (other days)
    // Note that reverse = ascending dayIds
    for (const [_, p] of selection.value) {
      // reverse XNOR behind => direction
      if (props.isreverse === delta < 0 ? p.dayid > photo.dayid : p.dayid < photo.dayid) {
        deselect(p);
      }
    }

    // Select everything behind upto the selected photo
    for (const p of list) {
      select(p);
    }

    // Do subsequent actions in the same direction
    multiSelectDelta.value = delta;
    return true;
  };

  // Prefer to select photos behind, i.e. moving ahead
  // If nothing behind is found, try ahead
  // Fall back to select the photo that was clicked
  const delta = multiSelectDelta.value ?? -1;
  backtrack(delta) || backtrack(-delta as typeof delta) || select(photo);

  // Update all days that were touched
  for (const dayid of touchedDays) {
    updateHeadSelected(props.heads.get(dayid)!);
  }
}

/** Select or deselect all photos in a head */
function selectHead(head: IHeadRow) {
  head.selected = !head.selected;
  for (const row of head.day.rows ?? []) {
    for (const photo of row.photos ?? []) {
      selectPhoto(photo, head.selected, true);
    }
  }
}

/** Check if the day for a photo is selected entirely */
function updateHeadSelected(head: IHeadRow) {
  let selected = true;

  // Check if all photos are selected
  for (const row of head.day.rows ?? []) {
    for (const photo of row.photos ?? []) {
      if (!(photo.flag & constants.FLAG_SELECTED)) {
        selected = false;
        break;
      }
    }
  }

  // Update head
  head.selected = selected;
}

/** Clear all selected photos */
function clear() {
  deselect(Array.from(selection.value.values()));
}

/** Deslect the given photos */
function deselect(photos: IPhoto[]) {
  const heads = new Set<IHeadRow>();
  photos.forEach((photo: IPhoto) => {
    photo.flag &= ~constants.FLAG_SELECTED;
    heads.add(props.heads.get(photo.dayid)!);
    selection.value.deleteBy(photo);
    selectionChanged();
  });
  heads.forEach(updateHeadSelected);
}

/** Restore selections from new day object */
function restoreDay(day: IDay) {
  if (empty()) return;

  // FileID => Photo for new day
  const dayMap = new Selection();
  day.detail?.forEach((photo) => dayMap.addBy(photo));

  selection.value.forEach((photo, key) => {
    // Process this day only
    if (photo.dayid !== day.dayid) {
      return;
    }

    // Remove all selections that are not in the new day
    const newPhoto = dayMap.get(key);
    if (!newPhoto) {
      selection.value.delete(key);
      return;
    }

    // Update the photo object
    selection.value.addBy(newPhoto);
    newPhoto.flag |= constants.FLAG_SELECTED;
  });

  selectionChanged();
}

/**
 * Download the currently selected files
 */
async function downloadSelection(sel: Selection) {
  if (sel.size >= 100 && !(await utils.dialogs.downloadItems(sel.size))) return;
  await dav.downloadFiles(sel.photosNoDupFileId().map((p) => p.fileid));
}

/**
 * Upload locally available files from the selection (NativeX only)
 */
async function uploadLocalSelection(sel: Selection) {
  const locals: IUploadNativeX[] = Array.from(sel.values())
    .filter((p) => utils.isLocalPhoto(p) && p.auid)
    .map((p) => ({ auid: p.auid!, filename: p.basename || p.auid! }));
  if (!locals.length) return;
  _m.modals.upload(locals);
}

/**
 * Check if all files selected currently are favorites
 */
function allSelectedFavorites(sel: Selection) {
  return Array.from(sel.values()).every((p) => p.flag & constants.FLAG_IS_FAVORITE);
}

/**
 * Favorite the currently selected photos
 */
async function favoriteSelection(sel: Selection) {
  const val = !allSelectedFavorites(sel);
  for await (const ids of dav.favoritePhotos(sel.photosNoDupFileId(), val)) {
    sel.photosFromFileIds(ids).forEach((photo) => dav.favoriteSetFlag(photo, val));
  }
  clear();
}

/**
 * Delete the currently selected photos
 */
async function deleteSelection(sel: Selection) {
  try {
    for await (const delIds of dav.deletePhotos(sel.photosNoDupFileId())) {
      deleteSelectedPhotosById(delIds, sel);
    }
  } catch (e) {
    console.error(e);
  }
}

/**
 * Share the currently selected photos
 */
async function shareSelection(sel: Selection) {
  _m.modals.sharePhotos(sel.photosNoDupFileId());
}

/**
 * Open the edit date dialog
 */
async function editMetadataSelection(sel: Selection, sections?: number[]) {
  _m.modals.editMetadata(sel.photosNoDupFileId(), sections);
}

/**
 * Force reindex the currently selected photos
 */
async function reindexSelection(sel: Selection) {
  _m.modals.reindex(sel.photosNoDupFileId());
}

/**
 * Open the files app with the selected file (one)
 * Opens a new window.
 */
async function viewInFolder(sel: Selection) {
  if (sel.size !== 1) return;
  dav.viewInFolder(sel.values().next().value!);
}

/**
 * Set the cover image for the current cluster
 */
async function setClusterCover(sel: Selection) {
  if (sel.size !== 1 || !routeIs.Cluster) return;
  if (await dav.setClusterCover(sel.values().next().value!)) {
    clear();
  }
}

/**
 * Archive the currently selected photos
 */
async function archiveSelection(sel: Selection) {
  if (sel.size >= 50 && !(await utils.dialogs.moveItems(sel.size))) return;

  for await (let delIds of dav.archiveFilesByIds(sel.photosNoDupFileId(), !routeIs.Archive)) {
    deleteSelectedPhotosById(delIds, sel);
  }
}

/**
 * Move selected photos to album
 */
async function addToAlbum(sel: Selection) {
  _m.modals.updateAlbums(sel.photosNoDupFileId());
}

/**
 * Move selected photos to folder
 */
async function moveToFolder(sel: Selection) {
  _m.modals.moveToFolder(sel.photosNoDupFileId());
}

/**
 * Move selected photos to another person
 */
async function moveSelectionToPerson(sel: Selection) {
  if (!config.show_face_rect && !routeIs.RecognizeUnassigned) {
    showError(t('memories', 'You must enable "Mark person in preview" to use this feature'));
    return;
  }
  _m.modals.moveToFace(Array.from(sel.values()));
}

/**
 * Remove currently selected photos from person
 */
async function removeSelectionFromPerson(sel: Selection) {
  // Make sure route is valid
  const { user, name } = route.params;
  if (!routeIs.Recognize || !user || !name) return;

  // Check photo ownership
  if (route.params.user?.toString() !== utils.uid) {
    showError(t('memories', 'Only user "{user}" can update this person', { user }));
    return;
  }

  // Make map to get back photo from faceid
  const map = new Map<number, IPhoto>();
  for (const photo of sel.values()) {
    if (photo.faceid) {
      map.set(photo.faceid, photo);
    }
  }
  const photos = Array.from(map.values());

  // Run WebDAV query
  for await (let delIds of dav.recognizeDeleteFaceImages(user.toString(), name.toString(), photos)) {
    const fileIds = delIds.map((id) => map.get(id)?.fileid).filter(utils.truthy);
    deleteSelectedPhotosById(fileIds, sel);
  }
}

/** Open viewer with given photo */
function openViewer(photo: IPhoto) {
  nativex.playTouchSound();
  _m.viewer.open(photo);
}

defineExpose({
  selectHead,
  clickSelectionIcon,
  clickPhoto,
  touchstartPhoto,
  touchendPhoto,
  touchmovePhoto,
  clear,
  restoreDay,
  deselect,
});
</script>

<style lang="scss" scoped>
.top-bar {
  position: absolute;
  top: 10px;
  right: min(60px, 4%);
  padding: 8px;
  width: 400px;
  max-width: 80%;
  background-color: var(--color-main-background);
  box-shadow: 0 0 2px gray;
  border-radius: 10px;
  opacity: 0.97;
  display: flex;
  vertical-align: middle;
  z-index: 300; // above top-matter and scroller
  box-sizing: border-box;

  > .text {
    flex-grow: 1;
    line-height: 42px;
    padding-left: 8px;
  }

  @media (max-width: 768px) {
    // sidebar is hidden below this point
    top: 0;
    left: 0;
    right: unset;
    position: fixed;
    width: 100vw;
    max-width: 100vw;
    border-radius: 0px;
    opacity: 1;
    padding-top: 3px;
    padding-bottom: 3px;
  }
}
</style>
