<template>
  <div>
    <div v-if="show" class="top-bar">
      <NcActions :inline="1">
        <NcActionButton
          :aria-label="t('memories', 'Cancel')"
          @click="clearSelection()"
        >
          {{ t("memories", "Cancel") }}
          <template #icon> <CloseIcon :size="20" /> </template>
        </NcActionButton>
      </NcActions>

      <div class="text">
        {{
          n("memories", "{n} selected", "{n} selected", size, {
            n: size,
          })
        }}
      </div>

      <NcActions :inline="1">
        <NcActionButton
          v-for="action of getActions()"
          :key="action.name"
          :aria-label="action.name"
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

    <!-- Selection Modals -->
    <EditDate ref="editDate" @refresh="refresh" />
    <EditExif ref="editExif" @refresh="refresh" />
    <FaceMoveModal
      ref="faceMoveModal"
      @moved="deletePhotos"
      :updateLoading="updateLoading"
    />
    <AddToAlbumModal ref="addToAlbumModal" @added="clearSelection" />
    <MoveToFolderModal ref="moveToFolderModal" @moved="refresh" />
  </div>
</template>

<script lang="ts">
import { defineComponent, PropType } from "vue";

import { showError } from "@nextcloud/dialogs";

import NcActions from "@nextcloud/vue/dist/Components/NcActions";
import NcActionButton from "@nextcloud/vue/dist/Components/NcActionButton";

import { translate as t, translatePlural as n } from "@nextcloud/l10n";
import {
  IDay,
  IHeadRow,
  IPhoto,
  IRow,
  IRowType,
  ISelectionAction,
} from "../types";
import { getCurrentUser } from "@nextcloud/auth";

import * as dav from "../services/DavRequests";
import * as utils from "../services/Utils";

import EditDate from "./modal/EditDate.vue";
import EditExif from "./modal/EditExif.vue";
import FaceMoveModal from "./modal/FaceMoveModal.vue";
import AddToAlbumModal from "./modal/AddToAlbumModal.vue";
import MoveToFolderModal from "./modal/MoveToFolderModal.vue";

import StarIcon from "vue-material-design-icons/Star.vue";
import DownloadIcon from "vue-material-design-icons/Download.vue";
import DeleteIcon from "vue-material-design-icons/TrashCanOutline.vue";
import EditFileIcon from "vue-material-design-icons/FileEdit.vue";
import EditClockIcon from "vue-material-design-icons/ClockEdit.vue";
import ArchiveIcon from "vue-material-design-icons/PackageDown.vue";
import UnarchiveIcon from "vue-material-design-icons/PackageUp.vue";
import OpenInNewIcon from "vue-material-design-icons/OpenInNew.vue";
import CloseIcon from "vue-material-design-icons/Close.vue";
import MoveIcon from "vue-material-design-icons/ImageMove.vue";
import AlbumsIcon from "vue-material-design-icons/ImageAlbum.vue";
import AlbumRemoveIcon from "vue-material-design-icons/BookRemove.vue";
import FolderMoveIcon from "vue-material-design-icons/FolderMove.vue";

type Selection = Map<number, IPhoto>;

export default defineComponent({
  name: "SelectionManager",
  components: {
    NcActions,
    NcActionButton,
    EditDate,
    EditExif,
    FaceMoveModal,
    AddToAlbumModal,
    MoveToFolderModal,

    CloseIcon,
  },

  props: {
    heads: Object as PropType<{ [dayid: number]: IHeadRow }>,
    /** List of rows for multi selection */
    rows: Array as PropType<IRow[]>,
    /** Rows are in ascending order (desc is normal) */
    isreverse: Boolean,
    /** Recycler element to scroll during touch multi-select */
    recycler: Object,
  },

  data: () => ({
    show: false,
    size: 0,
    selection: new Map<number, IPhoto>(),
    defaultActions: null as ISelectionAction[],

    touchAnchor: null as IPhoto,
    touchTimer: 0,
    touchPrevSel: null as Selection,
    prevOver: null as IPhoto,
    touchScrollInterval: 0,
    touchScrollDelta: 0,
    prevTouch: null as Touch,
  }),

  mounted() {
    // Make default actions
    this.defaultActions = [
      {
        name: t("memories", "Delete"),
        icon: DeleteIcon,
        callback: this.deleteSelection.bind(this),
        if: () => !this.routeIsAlbum(),
      },
      {
        name: t("memories", "Remove from album"),
        icon: AlbumRemoveIcon,
        callback: this.deleteSelection.bind(this),
        if: () => this.routeIsAlbum(),
      },
      {
        name: t("memories", "Download"),
        icon: DownloadIcon,
        callback: this.downloadSelection.bind(this),
        allowPublic: true,
        if: () => !this.allowDownload(),
      },
      {
        name: t("memories", "Favorite"),
        icon: StarIcon,
        callback: this.favoriteSelection.bind(this),
      },
      {
        name: t("memories", "Archive"),
        icon: ArchiveIcon,
        callback: this.archiveSelection.bind(this),
        if: () =>
          this.allowArchive() && !this.routeIsArchive() && !this.routeIsAlbum(),
      },
      {
        name: t("memories", "Unarchive"),
        icon: UnarchiveIcon,
        callback: this.archiveSelection.bind(this),
        if: () => this.allowArchive() && this.routeIsArchive(),
      },
      {
        name: t("memories", "Edit Date/Time"),
        icon: EditClockIcon,
        callback: this.editDateSelection.bind(this),
        if: () => !this.routeIsAlbum(),
      },
      {
        name: t("memories", "Edit EXIF Data"),
        icon: EditFileIcon,
        callback: this.editExifSelection.bind(this),
        if: () => this.selection.size === 1 && !this.routeIsAlbum(),
      },
      {
        name: t("memories", "View in folder"),
        icon: OpenInNewIcon,
        callback: this.viewInFolder.bind(this),
        if: () => this.selection.size === 1 && !this.routeIsAlbum(),
      },
      {
        name: t("memories", "Move to folder"),
        icon: FolderMoveIcon,
        callback: this.moveToFolder.bind(this),
        if: () => !this.routeIsAlbum() && !this.routeIsArchive(),
      },
      {
        name: t("memories", "Add to album"),
        icon: AlbumsIcon,
        callback: this.addToAlbum.bind(this),
        if: (self: typeof this) =>
          self.config_albumsEnabled && !self.routeIsAlbum(),
      },
      {
        name: t("memories", "Move to another person"),
        icon: MoveIcon,
        callback: this.moveSelectionToPerson.bind(this),
        if: () => this.$route.name === "recognize",
      },
      {
        name: t("memories", "Remove from person"),
        icon: CloseIcon,
        callback: this.removeSelectionFromPerson.bind(this),
        if: () => this.$route.name === "recognize",
      },
    ];

    // Ugly: globally exposed functions
    const getSel = (photo: IPhoto) => {
      const sel = new Map<number, IPhoto>();
      sel.set(photo.fileid, photo);
      return sel;
    };
    globalThis.editDate = (photo: IPhoto) =>
      this.editDateSelection(getSel(photo));
    globalThis.editExif = (photo: IPhoto) =>
      this.editExifSelection(getSel(photo));
  },

  watch: {
    show() {
      const klass = "has-top-bar";
      if (this.show) {
        document.body.classList.add(klass);
      } else {
        document.body.classList.remove(klass);
      }
    },
  },

  methods: {
    refresh() {
      this.$emit("refresh");
    },

    deletePhotos(photos: IPhoto[]) {
      this.$emit("delete", photos);
    },

    updateLoading(delta: number) {
      this.$emit("updateLoading", delta);
    },

    /** Download is not allowed on some public shares */
    allowDownload(): boolean {
      return this.state_noDownload;
    },

    /** Archive is not allowed only on folder routes */
    allowArchive() {
      return this.$route.name !== "folders";
    },

    /** Is archive route */
    routeIsArchive() {
      return this.$route.name === "archive";
    },

    /** Is album route */
    routeIsAlbum() {
      return this.config_albumsEnabled && this.$route.name === "albums";
    },

    /** Public route that can't modify anything */
    routeIsPublic() {
      return this.$route.name?.endsWith("-share");
    },

    /** Trigger to update props from selection set */
    selectionChanged() {
      this.show = this.selection.size > 0;
      this.size = this.selection.size;
    },

    /** Is this fileid (or anything if not specified) selected */
    has(fileid?: number) {
      if (fileid === undefined) {
        return this.selection.size > 0;
      }
      return this.selection.has(fileid);
    },

    /** Get the actions list */
    getActions(): ISelectionAction[] {
      return (
        this.defaultActions?.filter(
          (a) =>
            (!a.if || a.if(this)) && (!this.routeIsPublic() || a.allowPublic)
        ) || []
      );
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
    clickPhoto(photo: IPhoto, event: PointerEvent, rowIdx: number) {
      if (photo.flag & this.c.FLAG_PLACEHOLDER) return;
      if (event.pointerType === "touch") return; // let touch events handle this

      if (this.has()) {
        if (event.shiftKey) {
          this.selectMulti(photo, this.rows, rowIdx);
        } else {
          this.selectPhoto(photo);
        }
      } else {
        this.openViewer(photo);
      }
    },

    /** Tap on */
    touchstartPhoto(photo: IPhoto, event: TouchEvent, rowIdx: number) {
      if (photo.flag & this.c.FLAG_PLACEHOLDER) return;
      this.rows[rowIdx].virtualSticky = true;

      this.resetTouchParams();

      this.touchAnchor = photo;
      this.prevOver = photo;
      this.touchPrevSel = new Map(this.selection);
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

      if (this.touchTimer) this.clickPhoto(photo, {} as any, rowIdx);
      this.resetTouchParams();
    },

    resetTouchParams() {
      window.clearTimeout(this.touchTimer);
      this.touchTimer = 0;
      this.touchAnchor = null;
      this.prevOver = undefined;

      window.cancelAnimationFrame(this.touchScrollInterval);
      this.touchScrollInterval = 0;

      this.prevTouch = null;
    },

    /**
     * Tap over
     * photo and rowIdx are that of the *anchor*
     */
    touchmovePhoto(anchor: IPhoto, event: TouchEvent, rowIdx: number) {
      if (anchor.flag & this.c.FLAG_PLACEHOLDER) return;

      if (this.touchTimer) {
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

      // Use first touch -- can't do much
      const touch: Touch = event.touches[0];
      if (!touch) return;
      this.prevTouch = touch;

      // Scroll if at top or bottom
      const scrollUp = touch.clientY > 50 && touch.clientY < 110; // 50 topbar
      const scrollDown = touch.clientY > globalThis.windowInnerHeight - 60;
      if (scrollUp || scrollDown) {
        if (scrollUp) {
          this.touchScrollDelta = (-1 * (110 - touch.clientY)) / 3;
        } else {
          this.touchScrollDelta =
            (touch.clientY - globalThis.windowInnerHeight + 60) / 3;
        }

        if (this.touchAnchor && !this.touchScrollInterval) {
          let frameCount = 3;

          const fun = () => {
            this.recycler.$el.scrollTop += this.touchScrollDelta;

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
      } else {
        window.cancelAnimationFrame(this.touchScrollInterval);
        this.touchScrollInterval = 0;
      }

      this.touchMoveSelect(touch, rowIdx);
    },

    /** Multi-select triggered by touchmove */
    touchMoveSelect(touch: Touch, rowIdx: number) {
      // Which photo is the cursor over, if any
      const elems = document.elementsFromPoint(touch.clientX, touch.clientY);
      const photoComp: any = elems.find((e) => e.classList.contains("p-outer"));
      let overPhoto: IPhoto = photoComp?.__vue__?.data;
      if (overPhoto && overPhoto.flag & this.c.FLAG_PLACEHOLDER)
        overPhoto = null;

      // Do multi-selection "till" overPhoto "from" anchor
      // This logic is completely different from the desktop because of the
      // existence of a definitive "anchor" element. We just need to find
      // rverything between the anchor and the current photo
      if (overPhoto && this.prevOver !== overPhoto) {
        this.prevOver = overPhoto;

        // days reverse XOR rows reverse
        let reverse: boolean;
        if (overPhoto.dayid === this.touchAnchor.dayid) {
          const l = overPhoto.d.detail;
          const ai = l.indexOf(this.touchAnchor);
          const oi = l.indexOf(overPhoto);
          if (ai === -1 || oi === -1) return; // Shouldn't happen
          reverse = ai > oi;
        } else {
          reverse = overPhoto.dayid > this.touchAnchor.dayid != this.isreverse;
        }

        const newSelection = new Map(this.touchPrevSel);
        const updatedDays = new Set<number>();

        // Walk over rows
        let i = rowIdx;
        let j = this.rows[i].photos.indexOf(this.touchAnchor);
        while (true) {
          if (j < 0) {
            while (i > 0 && !this.rows[--i].photos);
            if (!this.rows[i].photos) break;
            j = this.rows[i].photos.length - 1;
            continue;
          } else if (j >= this.rows[i].photos.length) {
            while (i < this.rows.length - 1 && !this.rows[++i].photos);
            if (!this.rows[i].photos) break;
            j = 0;
            continue;
          }

          let p = this.rows[i]?.photos?.[j];
          if (!p) break; // shouldn't happen, ever

          // This is there now
          newSelection.set(p.fileid, p);

          // Perf: only update heads if not selected
          if (!(p.flag & this.c.FLAG_SELECTED)) {
            this.selectPhoto(p, true, true);
            updatedDays.add(p.dayid);
          }

          // We're trying to update too much -- something went wrong
          if (newSelection.size - this.selection.size > 50) break;

          // Check goal
          if (p === overPhoto) break;
          j += reverse ? -1 : 1;
        }

        // Remove unselected
        for (const [fileid, p] of this.selection) {
          if (!newSelection.has(fileid)) {
            this.selectPhoto(p, false, true);
            updatedDays.add(p.dayid);
          }
        }

        // Update heads
        for (const dayid of updatedDays) {
          this.updateHeadSelected(this.heads[dayid]);
        }

        this.$forceUpdate();
      }
    },

    /** Add a photo to selection list */
    selectPhoto(photo: IPhoto, val?: boolean, noUpdate?: boolean) {
      if (
        photo.flag & this.c.FLAG_PLACEHOLDER ||
        photo.flag & this.c.FLAG_IS_FOLDER ||
        photo.flag & this.c.FLAG_IS_TAG
      ) {
        return; // ignore placeholders
      }

      const nval = val ?? !this.selection.has(photo.fileid);
      if (nval) {
        photo.flag |= this.c.FLAG_SELECTED;
        this.selection.set(photo.fileid, photo);
        this.selectionChanged();
      } else {
        photo.flag &= ~this.c.FLAG_SELECTED;

        // Only do this if the photo in the selection set is this one.
        // The problem arises when there are duplicates (e.g. face rect)
        // in the list, which creates an inconsistent state if we do this.
        if (this.selection.get(photo.fileid) === photo) {
          this.selection.delete(photo.fileid);
          this.selectionChanged();
        }
      }

      if (!noUpdate) {
        this.updateHeadSelected(this.heads[photo.d.dayid]);
        this.$forceUpdate();
      }
    },

    /** Multi-select */
    selectMulti(photo: IPhoto, rows: IRow[], rowIdx: number) {
      const pRow = rows[rowIdx];
      const pIdx = pRow.photos.indexOf(photo);
      if (pIdx === -1) return;

      const updateDaySet = new Set<number>();
      let behind = [];
      let behindFound = false;

      // Look behind
      for (let i = rowIdx; i > rowIdx - 100; i--) {
        if (i < 0) break;
        if (rows[i].type !== IRowType.PHOTOS) continue;
        if (!rows[i].photos?.length) break;

        const sj = i === rowIdx ? pIdx : rows[i].photos.length - 1;
        for (let j = sj; j >= 0; j--) {
          const p = rows[i].photos[j];
          if (p.flag & this.c.FLAG_PLACEHOLDER || !p.fileid) continue;
          if (p.flag & this.c.FLAG_SELECTED) {
            behindFound = true;
            break;
          }
          behind.push(p);
          updateDaySet.add(p.d.dayid);
        }

        if (behindFound) break;
      }

      // Select everything behind
      if (behindFound) {
        // Clear everything in front in this day
        const pdIdx = photo.d.detail.indexOf(photo);
        for (let i = pdIdx + 1; i < photo.d.detail.length; i++) {
          const p = photo.d.detail[i];
          if (p.flag & this.c.FLAG_SELECTED) this.selectPhoto(p, false, true);
        }

        // Clear everything else in front
        Array.from(this.selection.values())
          .filter((p: IPhoto) => {
            return this.isreverse
              ? p.d.dayid > photo.d.dayid
              : p.d.dayid < photo.d.dayid;
          })
          .forEach((photo: IPhoto) => {
            this.selectPhoto(photo, false, true);
            updateDaySet.add(photo.d.dayid);
          });

        behind.forEach((p) => this.selectPhoto(p, true, true));
        updateDaySet.forEach((d) => this.updateHeadSelected(this.heads[d]));
        this.$forceUpdate();
      }
    },

    /** Select or deselect all photos in a head */
    selectHead(head: IHeadRow) {
      head.selected = !head.selected;
      for (const row of head.day.rows) {
        for (const photo of row.photos) {
          this.selectPhoto(photo, head.selected, true);
        }
      }
      this.$forceUpdate();
    },

    /** Check if the day for a photo is selected entirely */
    updateHeadSelected(head: IHeadRow) {
      let selected = true;

      // Check if all photos are selected
      for (const row of head.day.rows) {
        for (const photo of row.photos) {
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
    clearSelection(only?: IPhoto[]) {
      const heads = new Set<IHeadRow>();
      const toClear = only || this.selection.values();
      Array.from(toClear).forEach((photo: IPhoto) => {
        photo.flag &= ~this.c.FLAG_SELECTED;
        heads.add(this.heads[photo.d.dayid]);
        this.selection.delete(photo.fileid);
        this.selectionChanged();
      });
      heads.forEach(this.updateHeadSelected);
      this.$forceUpdate();
    },

    /** Restore selections from new day object */
    restoreDay(day: IDay) {
      if (!this.has()) {
        return;
      }

      // FileID => Photo for new day
      const dayMap = new Map<number, IPhoto>();
      day.detail.forEach((photo) => {
        dayMap.set(photo.fileid, photo);
      });

      this.selection.forEach((photo, fileid) => {
        // Process this day only
        if (photo.dayid !== day.dayid) {
          return;
        }

        // Remove all selections that are not in the new day
        if (!dayMap.has(fileid)) {
          this.selection.delete(fileid);
          return;
        }

        // Update the photo object
        const newPhoto = dayMap.get(fileid);
        this.selection.set(fileid, newPhoto);
        newPhoto.flag |= this.c.FLAG_SELECTED;
      });

      this.selectionChanged();
    },

    /**
     * Download the currently selected files
     */
    async downloadSelection(selection: Selection) {
      if (selection.size >= 100) {
        if (
          !confirm(
            this.t(
              "memories",
              "You are about to download a large number of files. Are you sure?"
            )
          )
        ) {
          return;
        }
      }
      await dav.downloadFilesByPhotos(Array.from(selection.values()));
    },

    /**
     * Check if all files selected currently are favorites
     */
    allSelectedFavorites(selection: Selection) {
      return Array.from(selection.values()).every(
        (p) => p.flag & this.c.FLAG_IS_FAVORITE
      );
    },

    /**
     * Favorite the currently selected photos
     */
    async favoriteSelection(selection: Selection) {
      const val = !this.allSelectedFavorites(selection);
      for await (const favIds of dav.favoritePhotos(
        Array.from(selection.values()),
        val
      )) {
      }
      this.clearSelection();
    },

    /**
     * Delete the currently selected photos
     */
    async deleteSelection(selection: Selection) {
      if (selection.size >= 100) {
        if (
          !confirm(
            this.t(
              "memories",
              "You are about to delete a large number of files. Are you sure?"
            )
          )
        ) {
          return;
        }
      }

      for await (const delIds of dav.deletePhotos(
        Array.from(selection.values())
      )) {
        const delPhotos = delIds
          .filter((id) => id)
          .map((id) => selection.get(id));
        this.deletePhotos(delPhotos);
      }
    },

    /**
     * Open the edit date dialog
     */
    async editDateSelection(selection: Selection) {
      (<any>this.$refs.editDate).open(Array.from(selection.values()));
    },

    /**
     * Open the edit date dialog
     */
    async editExifSelection(selection: Selection) {
      if (selection.size !== 1) return;
      (<any>this.$refs.editExif).open(selection.values().next().value);
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
     * Archive the currently selected photos
     */
    async archiveSelection(selection: Selection) {
      if (selection.size >= 100) {
        if (
          !confirm(
            this.t(
              "memories",
              "You are about to touch a large number of files. Are you sure?"
            )
          )
        ) {
          return;
        }
      }

      for await (let delIds of dav.archiveFilesByIds(
        Array.from(selection.keys()),
        !this.routeIsArchive()
      )) {
        delIds = delIds.filter((x) => x);
        if (delIds.length === 0) {
          continue;
        }
        const delPhotos = delIds.map((id) => selection.get(id));
        this.deletePhotos(delPhotos);
      }
    },

    /**
     * Move selected photos to album
     */
    async addToAlbum(selection: Selection) {
      (<any>this.$refs.addToAlbumModal).open(Array.from(selection.values()));
    },

    /**
     * Move selected photos to folder
     */
    async moveToFolder(selection: Selection) {
      (<any>this.$refs.moveToFolderModal).open(Array.from(selection.values()));
    },

    /**
     * Move selected photos to another person
     */
    async moveSelectionToPerson(selection: Selection) {
      if (!this.config_showFaceRect) {
        showError(
          this.t(
            "memories",
            'You must enable "Mark person in preview" to use this feature'
          )
        );
        return;
      }
      (<any>this.$refs.faceMoveModal).open(Array.from(selection.values()));
    },

    /**
     * Remove currently selected photos from person
     */
    async removeSelectionFromPerson(selection: Selection) {
      // Make sure route is valid
      const { user, name } = this.$route.params;
      if (this.$route.name !== "recognize" || !user || !name) {
        return;
      }

      // Check photo ownership
      if (this.$route.params.user !== getCurrentUser()?.uid) {
        showError(
          this.t("memories", 'Only user "{user}" can update this person', {
            user,
          })
        );
        return;
      }

      // Run query
      for await (let delIds of dav.removeFaceImages(
        <string>user,
        <string>name,
        Array.from(selection.values())
      )) {
        const delPhotos = delIds
          .filter((x) => x)
          .map((id) => selection.get(id));
        this.deletePhotos(delPhotos);
      }
    },

    /** Open viewer with given photo */
    openViewer(photo: IPhoto) {
      this.$router.push({
        path: this.$route.path,
        query: this.$route.query,
        hash: utils.getViewerHash(photo),
      });
    },
  },
});
</script>

<style lang="scss" scoped>
.top-bar {
  position: absolute;
  top: 10px;
  right: 60px;
  padding: 8px;
  width: 400px;
  max-width: 100vw;
  background-color: var(--color-main-background);
  box-shadow: 0 0 2px gray;
  border-radius: 10px;
  opacity: 0.97;
  display: flex;
  vertical-align: middle;
  z-index: 100;

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
    border-radius: 0px;
    opacity: 1;
    padding-top: 3px;
    padding-bottom: 3px;
  }
}
</style>
