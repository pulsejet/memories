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
    <FaceMoveModal
      ref="faceMoveModal"
      @moved="deletePhotos"
      :updateLoading="updateLoading"
    />
    <AddToAlbumModal ref="addToAlbumModal" @added="clearSelection" />
  </div>
</template>

<script lang="ts">
import { Component, Emit, Mixins, Prop, Watch } from "vue-property-decorator";
import GlobalMixin from "../mixins/GlobalMixin";
import UserConfig from "../mixins/UserConfig";

import { showError } from "@nextcloud/dialogs";
import { NcActions, NcActionButton } from "@nextcloud/vue";
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
import EditDate from "./modal/EditDate.vue";
import FaceMoveModal from "./modal/FaceMoveModal.vue";
import AddToAlbumModal from "./modal/AddToAlbumModal.vue";

import StarIcon from "vue-material-design-icons/Star.vue";
import DownloadIcon from "vue-material-design-icons/Download.vue";
import DeleteIcon from "vue-material-design-icons/Delete.vue";
import EditIcon from "vue-material-design-icons/ClockEdit.vue";
import ArchiveIcon from "vue-material-design-icons/PackageDown.vue";
import UnarchiveIcon from "vue-material-design-icons/PackageUp.vue";
import OpenInNewIcon from "vue-material-design-icons/OpenInNew.vue";
import CloseIcon from "vue-material-design-icons/Close.vue";
import MoveIcon from "vue-material-design-icons/ImageMove.vue";
import AlbumsIcon from "vue-material-design-icons/ImageAlbum.vue";
import AlbumRemoveIcon from "vue-material-design-icons/BookRemove.vue";

type Selection = Map<number, IPhoto>;

@Component({
  components: {
    NcActions,
    NcActionButton,
    EditDate,
    FaceMoveModal,
    AddToAlbumModal,

    CloseIcon,
  },
})
export default class SelectionManager extends Mixins(GlobalMixin, UserConfig) {
  @Prop() public heads: { [dayid: number]: IHeadRow };

  /** Rows are in ascending order (desc is normal) */
  @Prop() public isreverse: boolean;

  private show = false;
  private size = 0;
  private readonly selection!: Selection;
  private readonly defaultActions: ISelectionAction[];

  @Emit("refresh")
  refresh() {}

  @Emit("delete")
  deletePhotos(photos: IPhoto[]) {}

  @Emit("updateLoading")
  updateLoading(delta: number) {}

  constructor() {
    super();

    this.selection = new Map<number, IPhoto>();

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
        callback: this.removeFromAlbum.bind(this),
        if: () => this.routeIsAlbum(),
      },
      {
        name: t("memories", "Download"),
        icon: DownloadIcon,
        callback: this.downloadSelection.bind(this),
        allowPublic: true,
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
        icon: EditIcon,
        callback: this.editDateSelection.bind(this),
      },
      {
        name: t("memories", "View in folder"),
        icon: OpenInNewIcon,
        callback: this.viewInFolder.bind(this),
        if: () => this.selection.size === 1 && !this.routeIsAlbum(),
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
        if: () => this.$route.name === "people",
      },
      {
        name: t("memories", "Remove from person"),
        icon: CloseIcon,
        callback: this.removeSelectionFromPerson.bind(this),
        if: () => this.$route.name === "people",
      },
    ];

    // Ugly: globally exposed functions
    globalThis.editDate = (photo: IPhoto) => {
      const sel = new Map<number, IPhoto>();
      sel.set(photo.fileid, photo);
      this.editDateSelection(sel);
    };
  }

  @Watch("show")
  onShowChange() {
    const klass = "has-top-bar";
    if (this.show) {
      document.body.classList.add(klass);
    } else {
      document.body.classList.remove(klass);
    }
  }

  private selectionChanged() {
    this.show = this.selection.size > 0;
    this.size = this.selection.size;
  }

  /** Is this fileid (or anything if not specified) selected */
  public has(fileid?: number) {
    if (fileid === undefined) {
      return this.selection.size > 0;
    }
    return this.selection.has(fileid);
  }

  /** Restore selections from new day object */
  public restoreDay(day: IDay) {
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
  }

  /** Click on an action */
  private async click(action: ISelectionAction) {
    try {
      this.updateLoading(1);
      await action.callback(this.selection);
    } catch (error) {
      console.error(error);
    } finally {
      this.updateLoading(-1);
    }
  }

  /** Get the actions list */
  private getActions(): ISelectionAction[] {
    return this.defaultActions.filter(
      (a) => (!a.if || a.if(this)) && (!this.routeIsPublic() || a.allowPublic)
    );
  }

  /** Clear all selected photos */
  public clearSelection(only?: IPhoto[]) {
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
  }

  /** Check if the day for a photo is selected entirely */
  private updateHeadSelected(head: IHeadRow) {
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
  }

  /** Add a photo to selection list */
  public selectPhoto(photo: IPhoto, val?: boolean, noUpdate?: boolean) {
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
  }

  /** Multi-select */
  public selectMulti(photo: IPhoto, rows: IRow[], rowIdx: number) {
    console.log("selectMulti", photo, rows, rowIdx);
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
        .filter((p) => {
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
  }

  /** Select or deselect all photos in a head */
  public selectHead(head: IHeadRow) {
    head.selected = !head.selected;
    for (const row of head.day.rows) {
      for (const photo of row.photos) {
        this.selectPhoto(photo, head.selected, true);
      }
    }
    this.$forceUpdate();
  }

  /**
   * Download the currently selected files
   */
  private async downloadSelection(selection: Selection) {
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
  }

  /**
   * Check if all files selected currently are favorites
   */
  private allSelectedFavorites(selection: Selection) {
    return Array.from(selection.values()).every(
      (p) => p.flag & this.c.FLAG_IS_FAVORITE
    );
  }

  /**
   * Favorite the currently selected photos
   */
  private async favoriteSelection(selection: Selection) {
    const val = !this.allSelectedFavorites(selection);
    for await (const favIds of dav.favoritePhotos(
      Array.from(selection.values()),
      val
    )) {
    }
    this.clearSelection();
  }

  /**
   * Delete the currently selected photos
   */
  private async deleteSelection(selection: Selection) {
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
  }

  /**
   * Open the edit date dialog
   */
  private async editDateSelection(selection: Selection) {
    (<any>this.$refs.editDate).open(Array.from(selection.values()));
  }

  /**
   * Open the files app with the selected file (one)
   * Opens a new window.
   */
  private async viewInFolder(selection: Selection) {
    if (selection.size !== 1) return;
    dav.viewInFolder(selection.values().next().value);
  }

  /**
   * Archive the currently selected photos
   */
  private async archiveSelection(selection: Selection) {
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
  }

  /** Archive is not allowed only on folder routes */
  private allowArchive() {
    return this.$route.name !== "folders";
  }

  /** Is archive route */
  private routeIsArchive() {
    return this.$route.name === "archive";
  }

  /** Is album route */
  private routeIsAlbum() {
    return this.config_albumsEnabled && this.$route.name === "albums";
  }

  /** Public route that can't modify anything */
  private routeIsPublic() {
    return this.$route.name === "folder-share";
  }

  /**
   * Move selected photos to album
   */
  private async addToAlbum(selection: Selection) {
    (<any>this.$refs.addToAlbumModal).open(Array.from(selection.values()));
  }

  /**
   * Remove selected photos from album
   */
  private async removeFromAlbum(selection: Selection) {
    try {
      this.updateLoading(1);
      const user = this.$route.params.user;
      const name = this.$route.params.name;
      const gen = dav.removeFromAlbum(
        user,
        name,
        Array.from(selection.values())
      );
      for await (const delIds of gen) {
        const delPhotos = delIds
          .filter((p) => p)
          .map((id) => selection.get(id));
        this.deletePhotos(delPhotos);
      }
    } catch (e) {
      console.error(e);
      showError(
        e?.message || this.t("memories", "Could not remove photos from album")
      );
    } finally {
      this.updateLoading(-1);
    }
  }

  /**
   * Move selected photos to another person
   */
  private async moveSelectionToPerson(selection: Selection) {
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
  }

  /**
   * Remove currently selected photos from person
   */
  private async removeSelectionFromPerson(selection: Selection) {
    // Make sure route is valid
    const { user, name } = this.$route.params;
    if (this.$route.name !== "people" || !user || !name) {
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
      user,
      name,
      Array.from(selection.values())
    )) {
      const delPhotos = delIds.filter((x) => x).map((id) => selection.get(id));
      this.deletePhotos(delPhotos);
    }
  }
}
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
