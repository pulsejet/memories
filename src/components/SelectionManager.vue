<template>
  <div>
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

      <NcActions :inline="1">
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
  </div>
</template>

<script lang="ts">
import { defineComponent, type PropType } from 'vue';

import { showError } from '@nextcloud/dialogs';

import NcActions from '@nextcloud/vue/dist/Components/NcActions.js';
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js';

import UserConfig from '@mixins/UserConfig';

import { translate as t } from '@services/l10n';
import * as dav from '@services/dav';
import * as utils from '@services/utils';
import * as nativex from '@native';

import ShareIcon from 'vue-material-design-icons/ShareVariant.vue';
import StarIcon from 'vue-material-design-icons/Star.vue';
import DownloadIcon from 'vue-material-design-icons/Download.vue';
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

import type { IDay, IHeadRow, IPhoto, IRow } from '@typings';
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
    console.assert(photo?.key, 'SelectionManager::addBy encountered a photo without a key');
    this.set(photo.key!, photo);
    return this;
  }

  getBy({ key }: { key?: string }): IPhoto | undefined {
    console.assert(key, 'SelectionManager::getBy encountered a photo without a key');
    return this.get(key!);
  }

  deleteBy({ key }: { key?: string }): boolean {
    console.assert(key, 'SelectionManager::deleteBy encountered a photo without a key');
    return this.delete(key!);
  }

  hasBy({ key }: { key?: string }): boolean {
    console.assert(key, 'SelectionManager::hasBy encountered a photo without a key');
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

export default defineComponent({
  name: 'SelectionManager',
  components: {
    NcActions,
    NcActionButton,
    CloseIcon,
  },

  mixins: [UserConfig],

  props: {
    heads: {
      type: Map as PropType<Map<number, IHeadRow>>,
      required: true,
    },
    /** List of rows for multi selection */
    rows: {
      type: Array as PropType<IRow[]>,
      required: true,
    },
    /** Rows are in ascending order (desc is normal) */
    isreverse: {
      type: Boolean,
      required: true,
    },
    /** Recycler element to scroll during touch multi-select */
    recycler: {
      type: HTMLDivElement,
      required: false,
    },
    /** Scroller manager associated with the timeline */
    scrollerManager: {
      type: Object as PropType<InstanceType<typeof ScrollerManager>>,
      required: false,
    },
  },

  emits: {
    updateLoading: (delta: number) => true,
  },

  data: () => ({
    show: false,
    size: 0,
    loading: 0,
    selection: new Selection(),
    defaultActions: null! as ISelectionAction[],

    touchAnchor: null as IPhoto | null,
    prevTouch: null as Touch | null,
    touchTimer: 0,
    touchMoved: false,
    touchPrevSel: null as Selection | null,
    prevOver: null as IPhoto | null,
    touchScrollInterval: 0,
    touchScrollDelta: 0,
    touchMoveSelFrame: 0,
    multiSelectDelta: null as 1 | -1 | null,
    shouldPersistSelections: false,
  }),

  mounted() {
    _m.selectionManager = {
      selectPhoto: this.selectPhoto.bind(this) as typeof this.selectPhoto,
    };

    // Make default actions
    this.defaultActions = [
      {
        name: t('memories', 'Delete'),
        icon: DeleteIcon,
        callback: this.deleteSelection.bind(this),
        if: () => !this.routeIsAlbums,
      },
      {
        name: t('memories', 'Remove from album'),
        icon: AlbumRemoveIcon,
        callback: this.deleteSelection.bind(this),
        if: () => this.routeIsAlbums,
      },
      {
        name: t('memories', 'Share'),
        icon: ShareIcon,
        callback: this.shareSelection.bind(this),
        if: () => !this.routeIsAlbums,
      },
      {
        name: t('memories', 'Download'),
        icon: DownloadIcon,
        callback: this.downloadSelection.bind(this),
        allowPublic: true,
        if: () => !this.initstate.noDownload,
      },
      {
        name: t('memories', 'Favorite'),
        icon: StarIcon,
        callback: this.favoriteSelection.bind(this),
      },
      {
        name: t('memories', 'Archive'),
        icon: ArchiveIcon,
        callback: this.archiveSelection.bind(this),
        if: () => !this.routeIsArchiveFolder() && !this.routeIsAlbums,
      },
      {
        name: t('memories', 'Unarchive'),
        icon: UnarchiveIcon,
        callback: this.archiveSelection.bind(this),
        if: () => this.routeIsArchiveFolder(),
      },
      {
        name: t('memories', 'Edit metadata'),
        icon: EditFileIcon,
        callback: this.editMetadataSelection.bind(this),
      },
      {
        name: t('memories', 'Rotate / Flip'),
        icon: RotateLeftIcon,
        callback: () => this.editMetadataSelection(this.selection, [5]),
      },
      {
        name: t('memories', 'View in folder'),
        icon: OpenInNewIcon,
        callback: this.viewInFolder.bind(this),
        if: () => this.selection.size === 1 && !this.routeIsAlbums,
      },
      {
        name: t('memories', 'Set as cover image'),
        icon: ImageCheckIcon,
        callback: this.setClusterCover.bind(this),
        if: () => this.selection.size === 1 && this.routeIsCluster && !this.routeIsRecognizeUnassigned,
      },
      {
        name: t('memories', 'Move to folder'),
        icon: FolderMoveIcon,
        callback: this.moveToFolder.bind(this),
        if: () => !this.routeIsAlbums && !this.routeIsArchiveFolder(),
      },
      {
        name: t('memories', 'Add to album'),
        icon: AlbumsIcon,
        callback: this.addToAlbum.bind(this),
        if: (self: any) => self.config.albums_enabled && !self.routeIsAlbums,
      },
      {
        id: 'face-move',
        name: t('memories', 'Move to person'),
        icon: MoveIcon,
        callback: this.moveSelectionToPerson.bind(this),
        if: () => this.routeIsRecognize,
      },
      {
        name: t('memories', 'Remove from person'),
        icon: CloseIcon,
        callback: this.removeSelectionFromPerson.bind(this),
        if: () => this.routeIsRecognize && !this.routeIsRecognizeUnassigned,
      },
    ];

    // Move face-move to start if unassigned faces
    if (this.routeIsRecognizeUnassigned) {
      const i = this.defaultActions.findIndex((a) => a.id === 'face-move');
      this.defaultActions.unshift(this.defaultActions.splice(i, 1)[0]);
    }

    // Subscribe to global events
    utils.bus.on('memories:albums:update', this.clear);
    utils.bus.on('memories:fragment:pop:selection', this.clear);
  },

  beforeDestroy() {
    // Unsubscribe from global events
    utils.bus.off('memories:albums:update', this.clear);
    utils.bus.off('memories:fragment:pop:selection', this.clear);
  },

  watch: {
    show(value: boolean, from: boolean) {
      utils.fragment.if(value, utils.fragment.types.selection);
    },
  },

  methods: {
    deleteSelectedPhotosById(delIds: number[], selection: Selection) {
      utils.bus.emit('memories:timeline:deleted', selection.photosFromFileIds(delIds));
    },

    updateLoading(delta: number) {
      this.loading += delta; // local (disable buttons)
      this.$emit('updateLoading', delta); // timeline (loading icon)
    },

    /** Is archive route */
    routeIsArchiveFolder() {
      // Check if the route itself is archive
      if (this.routeIsArchive) return true;

      // Check if route is folder and the path contains .archive
      if (this.routeIsFolders) {
        let path = this.$route.params.path || '';
        if (Array.isArray(path)) path = path.join('/');
        return ('/' + path + '/').includes('/.archive/');
      }

      return false;
    },

    /** Trigger to update props from selection set */
    selectionChanged() {
      this.show = this.selection.size > 0;
      this.size = this.selection.size;
    },

    /** Is the selection empty */
    empty(): boolean {
      return !this.selection.size;
    },

    /** Get the actions list */
    getActions(): ISelectionAction[] {
      return this.defaultActions?.filter((a) => (!a.if || a.if(this)) && (!this.routeIsPublic || a.allowPublic)) || [];
    },

    /** Click on an action */
    async click(action: ISelectionAction) {
      try {
        this.updateLoading(1);
        await action.callback(this.selection);
      } catch (error) {
        console.error(error);
      } finally {
        this.updateLoading(-1);
      }
    },

    /** Clicking on photo */
    clickPhoto(photo: IPhoto, event: PointerEvent | null, rowIdx: number) {
      if (photo.flag & this.c.FLAG_PLACEHOLDER) return;
      if (event?.pointerType === 'touch') return; // let touch events handle this
      if (event?.pointerType === 'mouse' && event?.button !== 0) return; // only left click for mouse

      if (!this.empty() || event?.ctrlKey || event?.shiftKey) {
        this.clickSelectionIcon(photo, event, rowIdx);
      } else {
        this.openViewer(photo);
      }
    },

    /** Clicking on checkmark icon */
    clickSelectionIcon(photo: IPhoto, event: PointerEvent | null, rowIdx: number) {
      if (!this.empty() && event?.shiftKey) {
        this.selectMulti(photo, this.rows, rowIdx);
      } else {
        this.selectPhoto(photo);
      }
    },

    /** Tap on */
    touchstartPhoto(photo: IPhoto, event: TouchEvent, rowIdx: number) {
      if (photo.flag & this.c.FLAG_PLACEHOLDER) return;

      // Bail if the user was scrolling the recycler recently
      // https://github.com/pulsejet/memories/issues/1066
      if (this.scrollerManager?.scrollingRecyclerNowTimer) return;

      // Prevent this element from being removed from the DOM
      // If it gets removed then subsequent touch events are not triggered
      this.rows[rowIdx].virtualSticky = true;

      this.resetTouchParams();

      this.touchAnchor = photo;
      this.prevOver = photo;
      this.prevTouch = event.touches[0];
      this.touchPrevSel = this.selection.clone();
      this.touchMoved = false;
      this.touchTimer = window.setTimeout(() => {
        if (this.touchAnchor === photo) {
          this.selectPhoto(photo, true);
        }
        this.touchTimer = 0;
      }, 600);
    },

    /** Tap off */
    touchendPhoto(photo: IPhoto, event: TouchEvent, rowIdx: number) {
      if (photo.flag & this.c.FLAG_PLACEHOLDER) return;
      delete this.rows[rowIdx].virtualSticky;

      if (this.touchTimer && !this.touchMoved) {
        // Register a single tap, only if the touch hadn't moved at all
        this.clickPhoto(photo, null, rowIdx);
      }

      this.resetTouchParams();
    },

    resetTouchParams() {
      this.touchAnchor = null;
      this.touchMoved = false;
      this.prevOver = null;

      window.clearTimeout(this.touchTimer);
      this.touchTimer = 0;

      window.cancelAnimationFrame(this.touchScrollInterval);
      this.touchScrollInterval = 0;

      window.cancelAnimationFrame(this.touchMoveSelFrame);
      this.touchMoveSelFrame = 0;

      this.prevTouch = null;
    },

    /**
     * Tap over
     * photo and rowIdx are that of the *anchor*
     */
    touchmovePhoto(anchor: IPhoto, event: TouchEvent, rowIdx: number) {
      if (anchor.flag & this.c.FLAG_PLACEHOLDER) return;

      // Use first touch -- can't do much
      const touch: Touch = event.touches[0];

      if (this.touchTimer) {
        // Regardless of whether we continue to run the timer,
        // we still need to mark that the touch had moved.
        // This is so that we can disregard the event if only
        // registering a tap event (not a long press).
        // https://github.com/pulsejet/memories/issues/516
        this.touchMoved = true;

        // To be more forgiving, check if touch is still
        // within 30px of anchor touch (prevTouch)
        if (
          this.prevTouch &&
          Math.abs(this.prevTouch.clientX - touch.clientX) < 30 &&
          Math.abs(this.prevTouch.clientY - touch.clientY) < 30
        ) {
          return;
        }

        // Touch is not held, just cancel
        window.clearTimeout(this.touchTimer);
        this.touchTimer = 0;
        this.touchAnchor = null;
        return;
      } else if (!this.touchAnchor) {
        // Touch was previously cancelled
        return;
      }

      // Prevent scrolling
      event.preventDefault();
      event.stopPropagation();

      // This should never happen
      if (!touch) return;
      this.prevTouch = touch;

      // Scroll if at top or bottom
      const scrollUp = touch.clientY < TOUCH_SELECT_CLAMP.top;
      const scrollDown = touch.clientY > _m.window.innerHeight - TOUCH_SELECT_CLAMP.bottom;
      if (scrollUp || scrollDown) {
        if (scrollUp) {
          this.touchScrollDelta = Math.max((touch.clientY - TOUCH_SELECT_CLAMP.top) / 3, -TOUCH_SELECT_CLAMP.maxDelta);
        } else {
          this.touchScrollDelta = Math.min(
            (touch.clientY - _m.window.innerHeight + TOUCH_SELECT_CLAMP.bottom) / 3,
            TOUCH_SELECT_CLAMP.maxDelta,
          );
        }

        if (this.touchAnchor && !this.touchScrollInterval) {
          let frameCount = 3;

          const fun = () => {
            if (!this.prevTouch) return;
            this.recycler!.scrollTop += this.touchScrollDelta;

            if (frameCount++ >= 3) {
              this.touchMoveSelect(this.prevTouch, rowIdx);
              frameCount = 0;
            }

            if (this.touchScrollInterval) {
              this.touchScrollInterval = window.requestAnimationFrame(fun);
            }
          };
          this.touchScrollInterval = window.requestAnimationFrame(fun);
        }
      } else if (this.touchScrollInterval) {
        window.cancelAnimationFrame(this.touchScrollInterval);
        this.touchScrollInterval = 0;
      }

      // Trigger multiselection update in next frame
      // If there's no anchor then this is pointless cause it gets cancelled
      // If already scrolling up/down then the effect will be called anyway
      if (this.touchAnchor && !this.touchScrollInterval && !this.touchMoveSelFrame) {
        this.touchMoveSelFrame = window.requestAnimationFrame(() => {
          this.touchMoveSelFrame = 0;
          this.touchMoveSelect(touch, rowIdx);
        });
      }
    },

    /** Multi-select triggered by touchmove */
    touchMoveSelect(touch: Touch, rowIdx: number) {
      // Assertions
      if (!this.touchAnchor) return;

      // Clamp the Y value to lie inside the recycler area
      const recyclerRect = this.recycler?.getBoundingClientRect();
      const clampedY = Math.max(
        (recyclerRect?.top ?? 0) + TOUCH_SELECT_CLAMP.bufferPx,
        Math.min((recyclerRect?.bottom ?? 0) - TOUCH_SELECT_CLAMP.bufferPx, touch.clientY),
      );

      // Which photo is the cursor over, if any
      const elem: any = document
        .elementsFromPoint(touch.clientX, clampedY)
        .find((e) => e.classList.contains('p-outer-super'));
      let overPhoto: IPhoto | null = elem?.__vue__?.data;
      if (overPhoto && overPhoto.flag & this.c.FLAG_PLACEHOLDER) overPhoto = null;

      // Do multi-selection "till" overPhoto "from" anchor
      // This logic is completely different from the desktop because of the
      // existence of a definitive "anchor" element. We just need to find
      // everything between the anchor and the current photo
      if (overPhoto && this.prevOver !== overPhoto) {
        this.prevOver = overPhoto;

        // days reverse XOR rows reverse
        let reverse: boolean;
        if (overPhoto.dayid === this.touchAnchor.dayid) {
          const l = overPhoto?.d?.detail;
          if (!l) return; // Shouldn't happen
          const ai = l.indexOf(this.touchAnchor);
          const oi = l.indexOf(overPhoto);
          if (ai === -1 || oi === -1) return; // Shouldn't happen
          reverse = ai > oi;
        } else {
          reverse = overPhoto.dayid > this.touchAnchor.dayid != this.isreverse;
        }

        const newSelection = this.touchPrevSel!.clone();
        const updatedDays = new Set<number>();

        // Walk over rows
        let i = rowIdx;
        let j = this.rows[i].photos?.indexOf(this.touchAnchor) ?? -2;
        if (j === -2) return; // row is not initialized yet?!
        while (true) {
          if (j < 0) {
            while (i > 0 && !this.rows[--i].photos);
            const plen = this.rows[i].photos?.length;
            if (!plen) break;
            j = plen - 1;
            continue;
          } else if (j >= this.rows[i].photos!.length) {
            while (i < this.rows.length - 1 && !this.rows[++i].photos);
            if (!this.rows[i].photos) break;
            j = 0;
            continue;
          }

          const photo = this.rows[i]?.photos?.[j];
          if (!photo) break; // shouldn't happen, ever

          // This is there now
          newSelection.addBy(photo);

          // Perf: only update heads if not selected
          if (!(photo.flag & this.c.FLAG_SELECTED)) {
            this.selectPhoto(photo, true, true);
            updatedDays.add(photo.dayid);
          }

          // We're trying to update too much -- something went wrong
          if (newSelection.size - this.selection.size > 50) break;

          // Check goal
          if (photo === overPhoto) break;
          j += reverse ? -1 : 1;
        }

        // Remove unselected
        for (const [_, photo] of this.selection) {
          if (!newSelection.hasBy(photo)) {
            this.selectPhoto(photo, false, true);
            updatedDays.add(photo.dayid);
          }
        }

        // Update heads
        for (const dayid of updatedDays) {
          this.updateHeadSelected(this.heads.get(dayid)!);
        }

        this.$forceUpdate();
      }
    },

    /** Add a photo to selection list */
    selectPhoto(photo: IPhoto, val?: boolean, noUpdate?: boolean) {
      if (photo.flag & this.c.FLAG_PLACEHOLDER) {
        return; // ignore placeholders
      }

      const nval = val ?? !this.selection.hasBy(photo);
      if (nval) {
        photo.flag |= this.c.FLAG_SELECTED;
        this.selection.addBy(photo);
        this.selectionChanged();
        console.log('added photo', this.selection);
      } else {
        photo.flag &= ~this.c.FLAG_SELECTED;
        this.selection.deleteBy(photo);
        this.selectionChanged();
        console.log('removed photo', this.selection);
      }

      if (!noUpdate) {
        this.updateHeadSelected(this.heads.get(photo.dayid)!);
        this.$forceUpdate();
      }
    },

    /** Multi-select */
    selectMulti(photo: IPhoto, rows: IRow[], rowIdx: number) {
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
            if (p.flag & this.c.FLAG_PLACEHOLDER || !p.fileid) continue;

            if (p.flag & this.c.FLAG_SELECTED) {
              // Found a selected photo, return everything excluding this
              return result;
            }

            result.push(p);
          }
        }
      };

      /**@brief  Select or de-select a photo and update touchedDays */
      const selfun = (p: IPhoto, val: boolean) => {
        this.selectPhoto(p, val, true);
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
          if (detail[i].flag & this.c.FLAG_SELECTED) {
            deselect(detail[i]);
          }
        }

        // De-select everything else in front (other days)
        // Note that reverse = ascending dayIds
        for (const [_, p] of this.selection) {
          // reverse XNOR behind => direction
          if (this.isreverse === delta < 0 ? p.dayid > photo.dayid : p.dayid < photo.dayid) {
            deselect(p);
          }
        }

        // Select everything behind upto the selected photo
        for (const p of list) {
          select(p);
        }

        // Do subsequent actions in the same direction
        this.multiSelectDelta = delta;
        return true;
      };

      // Prefer to select photos behind, i.e. moving ahead
      // If nothing behind is found, try ahead
      // Fall back to select the photo that was clicked
      const delta = this.multiSelectDelta ?? -1;
      backtrack(delta) || backtrack(-delta as typeof delta) || select(photo);

      // Force update for all days that were touched
      for (const dayid of touchedDays) {
        this.updateHeadSelected(this.heads.get(dayid)!);
      }

      this.$forceUpdate(); // set changes
    },

    /** Select or deselect all photos in a head */
    selectHead(head: IHeadRow) {
      head.selected = !head.selected;
      for (const row of head.day.rows ?? []) {
        for (const photo of row.photos ?? []) {
          this.selectPhoto(photo, head.selected, true);
        }
      }
      this.$forceUpdate();
    },

    /** Check if the day for a photo is selected entirely */
    updateHeadSelected(head: IHeadRow) {
      let selected = true;

      // Check if all photos are selected
      for (const row of head.day.rows ?? []) {
        for (const photo of row.photos ?? []) {
          if (!(photo.flag & this.c.FLAG_SELECTED)) {
            selected = false;
            break;
          }
        }
      }

      // Update head
      head.selected = selected;
    },

    /** Clear all selected photos */
    clear() {
      this.deselect(Array.from(this.selection.values()));
    },

    /** Deslect the given photos */
    deselect(photos: IPhoto[]) {
      const heads = new Set<IHeadRow>();
      photos.forEach((photo: IPhoto) => {
        photo.flag &= ~this.c.FLAG_SELECTED;
        heads.add(this.heads.get(photo.dayid)!);
        this.selection.deleteBy(photo);
        this.selectionChanged();
      });
      heads.forEach(this.updateHeadSelected);
      this.$forceUpdate();
    },

    /** Restore selections from new day object */
    restoreDay(day: IDay) {
      if (this.empty()) return;

      // FileID => Photo for new day
      const dayMap = new Selection();
      day.detail?.forEach((photo) => dayMap.addBy(photo));

      this.selection.forEach((photo, key) => {
        // Process this day only
        if (photo.dayid !== day.dayid) {
          return;
        }

        // Remove all selections that are not in the new day
        const newPhoto = dayMap.get(key);
        if (!newPhoto) {
          this.selection.delete(key);
          return;
        }

        // Update the photo object
        this.selection.addBy(newPhoto);
        newPhoto.flag |= this.c.FLAG_SELECTED;
      });

      this.selectionChanged();
    },

    /**
     * Download the currently selected files
     */
    async downloadSelection(selection: Selection) {
      if (selection.size >= 100 && !(await utils.dialogs.downloadItems(selection.size))) return;
      await dav.downloadFiles(selection.photosNoDupFileId().map((p) => p.fileid));
    },

    /**
     * Check if all files selected currently are favorites
     */
    allSelectedFavorites(selection: Selection) {
      return Array.from(selection.values()).every((p) => p.flag & this.c.FLAG_IS_FAVORITE);
    },

    /**
     * Favorite the currently selected photos
     */
    async favoriteSelection(selection: Selection) {
      const val = !this.allSelectedFavorites(selection);
      for await (const ids of dav.favoritePhotos(selection.photosNoDupFileId(), val)) {
        selection.photosFromFileIds(ids).forEach((photo) => dav.favoriteSetFlag(photo, val));
      }
      this.clear();
    },

    /**
     * Delete the currently selected photos
     */
    async deleteSelection(selection: Selection) {
      try {
        for await (const delIds of dav.deletePhotos(selection.photosNoDupFileId())) {
          this.deleteSelectedPhotosById(delIds, selection);
        }
      } catch (e) {
        console.error(e);
      }
    },

    /**
     * Share the currently selected photos
     */
    shareSelection(selection: Selection) {
      _m.modals.sharePhotos(selection.photosNoDupFileId());
    },

    /**
     * Open the edit date dialog
     */
    async editMetadataSelection(selection: Selection, sections?: number[]) {
      _m.modals.editMetadata(selection.photosNoDupFileId(), sections);
    },

    /**
     * Open the files app with the selected file (one)
     * Opens a new window.
     */
    async viewInFolder(selection: Selection) {
      if (selection.size !== 1) return;
      dav.viewInFolder(selection.values().next().value);
    },

    /**
     * Set the cover image for the current cluster
     */
    async setClusterCover(selection: Selection) {
      if (selection.size !== 1 || !this.routeIsCluster) return;
      if (await dav.setClusterCover(selection.values().next().value)) {
        this.clear();
      }
    },

    /**
     * Archive the currently selected photos
     */
    async archiveSelection(selection: Selection) {
      if (selection.size >= 50 && !(await utils.dialogs.moveItems(selection.size))) return;

      for await (let delIds of dav.archiveFilesByIds(selection.photosNoDupFileId(), !this.routeIsArchive)) {
        this.deleteSelectedPhotosById(delIds, selection);
      }
    },

    /**
     * Move selected photos to album
     */
    async addToAlbum(selection: Selection) {
      _m.modals.updateAlbums(selection.photosNoDupFileId());
    },

    /**
     * Move selected photos to folder
     */
    async moveToFolder(selection: Selection) {
      _m.modals.moveToFolder(selection.photosNoDupFileId());
    },

    /**
     * Move selected photos to another person
     */
    async moveSelectionToPerson(selection: Selection) {
      if (!this.config.show_face_rect && !this.routeIsRecognizeUnassigned) {
        showError(this.t('memories', 'You must enable "Mark person in preview" to use this feature'));
        return;
      }
      _m.modals.moveToFace(Array.from(selection.values()));
    },

    /**
     * Remove currently selected photos from person
     */
    async removeSelectionFromPerson(selection: Selection) {
      // Make sure route is valid
      const { user, name } = this.$route.params;
      if (!this.routeIsRecognize || !user || !name) return;

      // Check photo ownership
      if (this.$route.params.user !== utils.uid) {
        showError(this.t('memories', 'Only user "{user}" can update this person', { user }));
        return;
      }

      // Make map to get back photo from faceid
      const map = new Map<number, IPhoto>();
      for (const photo of selection.values()) {
        if (photo.faceid) {
          map.set(photo.faceid, photo);
        }
      }
      const photos = Array.from(map.values());

      // Run WebDAV query
      for await (let delIds of dav.recognizeDeleteFaceImages(user, name, photos)) {
        const fileIds = delIds.map((id) => map.get(id)?.fileid).filter(utils.truthy);
        this.deleteSelectedPhotosById(fileIds, selection);
      }
    },

    /** Open viewer with given photo */
    openViewer(photo: IPhoto) {
      nativex.playTouchSound();
      _m.viewer.open(photo);
    },
  },
});
</script>

<style lang="scss" scoped>
.top-bar {
  position: absolute;
  top: 10px;
  right: min(60px, 10%);
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

  > .text {
    flex-grow: 1;
    line-height: 42px;
    padding-left: 8px;
  }

  @media (max-width: 1024px) {
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
