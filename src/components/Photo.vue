<template>
    <div class="photo-container"
        :class="{ 'selected': (data.flag & c.FLAG_SELECTED) }">

        <div class="icon-checkmark select"
             v-if="!(data.flag & c.FLAG_PLACEHOLDER)"
             @click="toggleSelect"></div>

        <div v-if="data.isvideo" class="icon-video-white"></div>
        <div class="img-outer" :style="{
                width: rowHeight + 'px',
                height: rowHeight + 'px',
            }">
            <img
                @click="click"
                @contextmenu="contextmenu"
                @touchstart="touchstart"
                @touchmove="touchend"
                @touchend="touchend"
                @touchcancel="touchend"
                :src="(data.flag & c.FLAG_PLACEHOLDER) ? undefined : getPreviewUrl(data.fileid, data.etag)"
                :key="data.fileid"
                @load = "data.l = Math.random()"
                @error="(e) => e.target.src='/apps/memories/img/error.svg'" />
        </div>
    </div>
</template>

<script>
import * as dav from "../services/DavRequests";
import constants from "../mixins/constants"
import { getPreviewUrl } from "../services/FileUtils";

export default {
    name: 'Photo',
    data() {
        return {
            touchTimer: 0,
            c: constants,
        }
    },
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

        /** Pass to parent */
        click() {
            this.$emit('clickImg', this);
        },

        /** Open viewer */
        async openFile() {
            // Check if this is a placeholder
            if (this.data.flag & constants.FLAG_PLACEHOLDER) {
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

        toggleSelect() {
            if (this.data.flag & constants.FLAG_PLACEHOLDER) {
                return;
            }
            this.$emit('select', this.data);
        },

        touchstart() {
            this.touchTimer = setTimeout(() => {
                this.toggleSelect();
                this.touchTimer = 0;
            }, 600);
        },

        contextmenu(e) {
            e.preventDefault();
            e.stopPropagation();
        },

        touchend() {
            if (this.touchTimer) {
                clearTimeout(this.touchTimer);
                this.touchTimer = 0;
            }
        },
    }
}
</script>

<style scoped>
.photo-container:hover .icon-checkmark {
    opacity: 0.7;
}
.photo-container.selected .icon-checkmark {
    opacity: 0.9;
    filter: invert();
}
.photo-container.selected .img-outer {
    padding: 6%;
}
.photo-container.selected img {
    box-shadow: 0 0 6px 2px var(--color-primary);
}
.icon-checkmark {
    opacity: 0;
    position: absolute;
    top: 8px; left: 8px;
    background-color: var(--color-main-background);
    border-radius: 50%;
    background-size: 80%;
    padding: 5px;
    cursor: pointer;
}

.icon-video-white {
    position: absolute;
    top: 8px; right: 8px;
}
.img-outer {
    padding: 2px;
    transition: all 0.1s ease-in-out;
}
img {
    background-clip: content-box;
    background-color: var(--color-loading-light);
    object-fit: cover;
    border-radius: 3%;
    cursor: pointer;
    width: 100%; height: 100%;

    -webkit-tap-highlight-color: transparent;
    -webkit-touch-callout: none;
    user-select: none;
}
</style>