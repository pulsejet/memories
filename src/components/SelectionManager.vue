<template>
    <div>
        <div v-if="selection.size > 0" class="top-bar">
            <NcActions>
                <NcActionButton
                    :aria-label="t('memories', 'Cancel')"
                    @click="clearSelection()">
                    {{ t('memories', 'Cancel') }}
                    <template #icon> <CloseIcon :size="20" /> </template>
                </NcActionButton>
            </NcActions>

            <div class="text">
                {{ n("memories", "{n} selected", "{n} selected", selection.size, { n: selection.size }) }}
            </div>

            <NcActions :inline="1">
                <NcActionButton
                    :aria-label="t('memories', 'Delete')"
                    @click="deleteSelection">
                    {{ t('memories', 'Delete') }}
                    <template #icon> <Delete :size="20" /> </template>
                </NcActionButton>
                <NcActionButton
                    :aria-label="t('memories', 'Download')"
                    @click="downloadSelection" close-after-click>
                    {{ t('memories', 'Download') }}
                    <template #icon> <Download :size="20" /> </template>
                </NcActionButton>
                <NcActionButton
                    :aria-label="t('memories', 'Favorite')"
                    @click="favoriteSelection" close-after-click>
                    {{ t('memories', 'Favorite') }}
                    <template #icon> <Star :size="20" /> </template>
                </NcActionButton>

                <template v-if="allowArchive()">
                    <NcActionButton
                        v-if="!routeIsArchive()"
                        :aria-label="t('memories', 'Archive')"
                        @click="archiveSelection" close-after-click>
                        {{ t('memories', 'Archive') }}
                        <template #icon> <ArchiveIcon :size="20" /> </template>
                    </NcActionButton>
                    <NcActionButton
                        v-else
                        :aria-label="t('memories', 'Unarchive')"
                        @click="archiveSelection" close-after-click>
                        {{ t('memories', 'Unarchive') }}
                        <template #icon> <UnarchiveIcon :size="20" /> </template>
                    </NcActionButton>
                </template>

                <NcActionButton
                    :aria-label="t('memories', 'Edit Date/Time')"
                    @click="editDateSelection" close-after-click>
                    {{ t('memories', 'Edit Date/Time') }}
                    <template #icon> <EditIcon :size="20" /> </template>
                </NcActionButton>

                <template v-if="selection.size === 1">
                    <NcActionButton
                        :aria-label="t('memories', 'View in folder')"
                        @click="viewInFolder" close-after-click>
                        {{ t('memories', 'View in folder') }}
                        <template #icon> <OpenInNewIcon :size="20" /> </template>
                    </NcActionButton>
                </template>

                <NcActionButton
                    v-if="$route.name === 'people'"
                    :aria-label="t('memories', 'Remove from person')"
                    @click="removeSelectionFromPerson" close-after-click>
                    {{ t('memories', 'Remove from person') }}
                    <template #icon> <CloseIcon :size="20" /> </template>
                </NcActionButton>
            </NcActions>
        </div>

        <EditDate ref="editDate" @refresh="refresh" />
    </div>
</template>

<script lang="ts">
import { Component, Emit, Mixins, Prop } from 'vue-property-decorator';
import { IHeadRow, IPhoto } from '../types';
import { generateUrl } from '@nextcloud/router'
import { NcActions, NcActionButton } from '@nextcloud/vue';

import EditDate from "./modal/EditDate.vue"

import Star from 'vue-material-design-icons/Star.vue';
import Download from 'vue-material-design-icons/Download.vue';
import Delete from 'vue-material-design-icons/Delete.vue';
import EditIcon from 'vue-material-design-icons/ClockEdit.vue';
import ArchiveIcon from 'vue-material-design-icons/PackageDown.vue';
import UnarchiveIcon from 'vue-material-design-icons/PackageUp.vue';
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue';
import CloseIcon from 'vue-material-design-icons/Close.vue';

import GlobalMixin from '../mixins/GlobalMixin';
import * as dav from "../services/DavRequests";

@Component({
    components: {
        NcActions,
        NcActionButton,
        EditDate,

        Star,
        Download,
        Delete,
        EditIcon,
        ArchiveIcon,
        UnarchiveIcon,
        OpenInNewIcon,
        CloseIcon,
    },
})
export default class SelectionHandler extends Mixins(GlobalMixin) {
    @Prop() public selection: Map<number, IPhoto>;
    @Prop() public heads: { [dayid: number]: IHeadRow };

    @Emit('refresh')
    refresh() {}

    @Emit('delete')
    delete(photos: IPhoto[]) {}

    @Emit('updateLoading')
    updateLoading(delta: number) {}

    /** Clear all selected photos */
    public clearSelection(only?: IPhoto[]) {
        const heads = new Set<IHeadRow>();
        const toClear = only || this.selection.values();
        Array.from(toClear).forEach((photo: IPhoto) => {
            photo.flag &= ~this.c.FLAG_SELECTED;
            heads.add(this.heads[photo.d.dayid]);
            this.selection.delete(photo.fileid);
        });
        heads.forEach(this.updateHeadSelected);
        this.$forceUpdate();
    }

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
    }

    /** Add a photo to selection list */
    selectPhoto(photo: IPhoto, val?: boolean, noUpdate?: boolean) {
        if (photo.flag & this.c.FLAG_PLACEHOLDER ||
            photo.flag & this.c.FLAG_IS_FOLDER ||
            photo.flag & this.c.FLAG_IS_TAG
        ) {
            return; // ignore placeholders
        }

        const nval = val ?? !this.selection.has(photo.fileid);
        if (nval) {
            photo.flag |= this.c.FLAG_SELECTED;
            this.selection.set(photo.fileid, photo);
        } else {
            photo.flag &= ~this.c.FLAG_SELECTED;
            this.selection.delete(photo.fileid);
        }

        if (!noUpdate) {
            this.updateHeadSelected(this.heads[photo.d.dayid]);
            this.$forceUpdate();
        }
    }

    /** Select or deselect all photos in a head */
    selectHead(head: IHeadRow) {
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
    async downloadSelection() {
        if (this.selection.size >= 100) {
            if (!confirm(this.t("memories", "You are about to download a large number of files. Are you sure?"))) {
                return;
            }
        }
        await dav.downloadFilesByIds(Array.from(this.selection.keys()));
    }

    /**
     * Check if all files selected currently are favorites
     */
    allSelectedFavorites() {
        return Array.from(this.selection.values()).every(p => p.flag & this.c.FLAG_IS_FAVORITE);
    }

    /**
     * Favorite the currently selected photos
     */
    async favoriteSelection() {
        try {
            const val = !this.allSelectedFavorites();
            this.updateLoading(1);
            for await (const favIds of dav.favoriteFilesByIds(Array.from(this.selection.keys()), val)) {
                favIds.forEach(id => {
                    const photo = this.selection.get(id);
                    if (!photo) {
                        return;
                    }

                    if (val) {
                        photo.flag |= this.c.FLAG_IS_FAVORITE;
                    } else {
                        photo.flag &= ~this.c.FLAG_IS_FAVORITE;
                    }
                });
            }
            this.clearSelection();
        } finally {
            this.updateLoading(-1);
        }
    }

    /**
     * Delete the currently selected photos
     */
    async deleteSelection() {
        if (this.selection.size >= 100) {
            if (!confirm(this.t("memories", "You are about to delete a large number of files. Are you sure?"))) {
                return;
            }
        }

        try {
            this.updateLoading(1);
            for await (const delIds of dav.deleteFilesByIds(Array.from(this.selection.keys()))) {
                const delPhotos = delIds.map(id => this.selection.get(id));
                await this.delete(delPhotos);
            }
        } catch (error) {
            console.error(error);
        } finally {
            this.updateLoading(-1);
        }
    }

    /**
     * Open the edit date dialog
     */
    async editDateSelection() {
        (<any>this.$refs.editDate).open(Array.from(this.selection.values()));
    }

    /**
     * Open the files app with the selected file (one)
     * Opens a new window.
     */
    async viewInFolder() {
        if (this.selection.size !== 1) return;

        const photo: IPhoto = this.selection.values().next().value;
        const f = await dav.getFiles([photo.fileid]);
        if (f.length === 0) return;

        const file = f[0];
        const dirPath = file.filename.split('/').slice(0, -1).join('/')
        const url = generateUrl(`/apps/files/?dir=${dirPath}&scrollto=${file.fileid}&openfile=${file.fileid}`);
        window.open(url, '_blank');
    }

    /**
     * Archive the currently selected photos
     */
    async archiveSelection() {
        if (this.selection.size >= 100) {
            if (!confirm(this.t("memories", "You are about to touch a large number of files. Are you sure?"))) {
                return;
            }
        }

        try {
            this.updateLoading(1);
            for await (let delIds of dav.archiveFilesByIds(Array.from(this.selection.keys()), !this.routeIsArchive())) {
                delIds = delIds.filter(x => x);
                if (delIds.length === 0) {
                    continue
                }
                const delPhotos = delIds.map(id => this.selection.get(id));
                await this.delete(delPhotos);
            }
        } catch (error) {
            console.error(error);
        } finally {
            this.updateLoading(-1);
        }
    }

    /** Archive is allowed only on timeline routes */
    allowArchive() {
        return this.$route.name === 'timeline'  ||
               this.$route.name === 'favorites' ||
               this.$route.name === 'videos'    ||
               this.$route.name === 'thisday'   ||
               this.$route.name === 'archive';
    }

    /** Is archive route */
    routeIsArchive() {
        return this.$route.name === 'archive';
    }

    /**
     * Remove currently selected photos from person
     */
    async removeSelectionFromPerson() {
        // Make sure route is valid
        const { user, name } = this.$route.params;
        if (this.$route.name !== "people" || !user || !name) {
            return;
        }

        // Run query
        try {
            this.updateLoading(1);
            for await (let delIds of dav.removeFaceImages(user, name, Array.from(this.selection.keys()))) {
                const delPhotos = delIds.filter(x => x).map(id => this.selection.get(id));
                this.delete(delPhotos);
            }
        } catch (error) {
            console.error(error);
        } finally {
            this.updateLoading(-1);
        }
    }
}
</script>

<style lang="scss" scoped>
.top-bar {
    position: absolute;
    top: 10px; right: 60px;
    padding: 8px;
    width: 400px;
    max-width: calc(100vw - 30px);
    background-color: var(--color-main-background);
    box-shadow: 0 0 2px gray;
    border-radius: 10px;
    opacity: 0.95;
    display: flex;
    vertical-align: middle;
    z-index: 100;

    > .text {
        flex-grow: 1;
        line-height: 40px;
        padding-left: 8px;
    }

    @media (max-width: 768px) {
        top: 35px; right: 15px;
    }
}
</style>
