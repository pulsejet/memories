<template>
    <div class="p-outer"
        :class="{
            'selected': (data.flag & c.FLAG_SELECTED),
            'p-loading': !(data.flag & c.FLAG_LOADED),
            'load-fail': (data.flag & c.FLAG_LOAD_FAIL),
            'leaving': (data.flag & c.FLAG_LEAVING),
            'exit-left': (data.flag & c.FLAG_EXIT_LEFT),
            'enter-right': (data.flag & c.FLAG_ENTER_RIGHT),
        }">

        <div class="icon-checkmark select"
             v-if="!(data.flag & c.FLAG_PLACEHOLDER)"
             @click="toggleSelect"></div>

        <div v-if="data.isvideo" class="icon-video-white"></div>
        <div v-if="data.flag & c.FLAG_IS_FAVORITE" class="icon-starred"></div>

        <div class="img-outer" :style="{
                width: rowHeight + 'px',
                height: rowHeight + 'px',
            }">
            <img
                :src="getUrl()"
                :key="data.fileid"

                @click="click"
                @error="error"
                @load = "data.flag |= c.FLAG_LOADED"

                @contextmenu="contextmenu"
                @touchstart="touchstart"
                @touchmove="touchend"
                @touchend="touchend"
                @touchcancel="touchend" />
        </div>
    </div>
</template>

<script>
import * as dav from "../services/DavRequests";
import constants from "../mixins/constants"
import { getPreviewUrl } from "../services/FileUtils";
import { generateUrl } from '@nextcloud/router'

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
        /** Get URL for image to show */
        getUrl() {
            if (this.data.flag & constants.FLAG_PLACEHOLDER) {
                return undefined;
            } else if (this.data.flag & constants.FLAG_LOAD_FAIL) {
                return generateUrl('apps/memories/img/error.svg');
            } else {
                return getPreviewUrl(this.data.fileid, this.data.etag);
            }
        },

        /** Error in loading image */
        error(e) {
            this.data.flag |= (constants.FLAG_LOADED | constants.FLAG_LOAD_FAIL);
        },

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
            this.$emit('reprocess', remIds, new Set([this.day]));
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

<style lang="scss" scoped>
/* Container and selection */
.p-outer {
    will-change: transform, opacity;
    transform: translateZ(0);

    &.leaving {
        transition: all 0.2s ease-in;
        transform: scale(0.9);
        opacity: 0;
    }
    &.exit-left {
        transition: all 0.2s ease-in;
        transform: translateX(-20%);
        opacity: 0.4;
    }
    &.enter-right {
        animation: enter-right 0.2s ease-out forwards;
    }
}

@keyframes enter-right {
    from { transform: translateX(20%); opacity: 0.4; }
    to { transform: translateX(0); opacity: 1; }
}

.icon-checkmark {
    position: absolute;
    top: 10px; left: 10px;
    z-index: 100;
    background-color: var(--color-main-background);
    border-radius: 50%;
    background-size: 80%;
    padding: 5px;
    cursor: pointer;
    opacity: 0;

    .p-outer:hover & { opacity: 0.7; }
    .selected & { opacity: 0.9; filter: invert(1); }
}

/* Extra icons */
.icon-video-white {
    position: absolute;
    background-size: 100%;
    height: 20px; width: 20px;
    top: 10px; right: 10px;
    z-index: 100;
}
.icon-starred {
    position: absolute;
    background-size: 100%;
    height: 24px; width: 24px;
    bottom: 10px; left: 10px;
    z-index: 100;
    pointer-events: none;
}

/* Actual image */
div.img-outer {
    padding: 2px;
    transition: padding 0.1s ease,              /* selection */
                background-color 0.3s ease;     /* image fade in */
    background-clip: content-box, padding-box;

    .selected & { padding: 6%; }
    .p-loading & { background-color: var(--color-loading-light); }
    .load-fail & { background-color: var(--color-loading-light); }
}
img {
    background-clip: content-box;
    object-fit: cover;
    cursor: pointer;
    width: 100%; height: 100%;
    opacity: 1;
    transition: opacity 0.3s ease;

    -webkit-tap-highlight-color: transparent;
    -webkit-touch-callout: none;
    user-select: none;

    .selected & { box-shadow: 0 0 6px 2px var(--color-primary); }
    .p-loading & { opacity: 0; }
}
</style>