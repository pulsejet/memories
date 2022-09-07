<template>
    <div>
        <div v-if="data.isvideo" class="icon-video-white"></div>
        <img
            @click="openFile()"
            :src="data.ph ? undefined : getPreviewUrl(data.fileid, data.etag)"
            :key="data.fileid"
            @load = "data.l = Math.random()"
            @error="(e) => e.target.src='/apps/memories/img/error.svg'"
            v-bind:style="{
                width: rowHeight + 'px',
                height: rowHeight + 'px',
            }"/>
    </div>
</template>

<script>
import * as dav from "../services/DavRequests";
import { getPreviewUrl } from "../services/FileUtils";

export default {
    name: 'Folder',
    props: {
        data: {
            type: Object,
            required: true
        },
        rowHeight: {
            type: Number,
            required: true,
        },
        day: {
            type: Object,
            required: true,
        },
    },
    methods: {
        /** Passthrough */
        getPreviewUrl: getPreviewUrl,

        /** Open viewer */
        async openFile() {
            // Check if this is a placeholder
            if (this.data.ph) {
                return;
            }

            // Check if already loaded fileInfos or load
            let fileInfos = this.day.fileInfos;
            if (!fileInfos) {
                const ids = this.day.detail.map(p => p.fileid);
                try {
                    this.loading = true;
                    fileInfos = await dav.getFiles(ids);
                } catch (e) {
                    console.error('Failed to load fileInfos', e);
                } finally {
                    this.loading = false;
                }
                if (fileInfos.length === 0) {
                    return;
                }

                // Fix sorting of the fileInfos
                const itemPositions = {};
                for (const [index, id] of ids.entries()) {
                    itemPositions[id] = index;
                }
                fileInfos.sort(function (a, b) {
                    return itemPositions[a.fileid] - itemPositions[b.fileid];
                });

                // Store in day with a original copy
                this.day.fileInfos = fileInfos;
                this.day.fiOrigIds = new Set(fileInfos.map(f => f.fileid));
            }

            // Get this photo in the fileInfos
            const photo = fileInfos.find(d => Number(d.fileid) === Number(this.data.fileid));
            if (!photo) {
                alert('Cannot find this photo anymore!');
                return;
            }

            // Key to store sidebar state
            const SIDEBAR_KEY = 'memories:sidebar-open';

            // Open viewer
            OCA.Viewer.open({
                path: photo.filename,   // path
                list: fileInfos,        // file list
                canLoop: false,         // don't loop
                onClose: () => {        // on viewer close
                    if (OCA.Files.Sidebar.file) {
                        localStorage.setItem(SIDEBAR_KEY, '1');
                    } else {
                        localStorage.removeItem(SIDEBAR_KEY);
                    }
                    OCA.Files.Sidebar.close();

                    // Check for any deleted files and remove them from the main view
                    this.processDeleted();
                },
            });

            // Restore sidebar state
            if (localStorage.getItem(SIDEBAR_KEY) === '1') {
                OCA.Files.Sidebar.open(photo.filename);
            }
        },

        /** Remove deleted files from main view */
        processDeleted() {
            // This is really an ugly hack, but the viewer
            // does not provide a way to get the deleted files

            // Compare new and old list of ids
            const newIds = new Set(this.day.fileInfos.map(f => f.fileid));
            const remIds = new Set([...this.day.fiOrigIds].filter(x => !newIds.has(x)));

            // Exit if nothing to do
            if (remIds.size === 0) {
                return;
            }
            this.day.fiOrigIds = newIds;

            // Remove deleted files from details
            this.day.detail = this.day.detail.filter(f => !remIds.has(f.fileid));
            this.day.count = this.day.detail.length;
            this.$emit('reprocess', this.day);
        },
    }
}
</script>

<style scoped>
.icon-video-white {
    position: absolute;
    top: 8px; right: 8px;
}
img {
    background-clip: content-box;
    background-color: var(--color-loading-light);
    padding: 2px;
    object-fit: cover;
    border-radius: 3%;
    cursor: pointer;
}
</style>