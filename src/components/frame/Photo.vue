<template>
    <div class="p-outer fill-block"
        :class="{
            'selected': (data.flag & c.FLAG_SELECTED),
            'placeholder': (data.flag & c.FLAG_PLACEHOLDER),
            'leaving': (data.flag & c.FLAG_LEAVING),
            'error': (data.flag & c.FLAG_LOAD_FAIL),
        }">

        <Check :size="15" class="select"
             v-if="!(data.flag & c.FLAG_PLACEHOLDER)"
             @click="toggleSelect" />

        <Video :size="20" v-if="data.flag & c.FLAG_IS_VIDEO" />
        <Star :size="20" v-if="data.flag & c.FLAG_IS_FAVORITE" />

        <div class="img-outer fill-block"
            @click="emitClick"
            @contextmenu="contextmenu"
            @touchstart="touchstart"
            @touchmove="touchend"
            @touchend="touchend"
            @touchcancel="touchend" >
            <img
                class="fill-block"
                :src="src()"
                :key="data.fileid"
                @error="error" />
        </div>
    </div>
</template>

<script lang="ts">
import { Component, Prop, Emit, Mixins } from 'vue-property-decorator';
import { IDay, IPhoto } from "../../types";

import { getPreviewUrl } from "../../services/FileUtils";
import errorsvg from "../../assets/error.svg";
import GlobalMixin from '../../mixins/GlobalMixin';

import Check from 'vue-material-design-icons/Check.vue';
import Video from 'vue-material-design-icons/Video.vue';
import Star from 'vue-material-design-icons/Star.vue';

@Component({
    components: {
        Check,
        Video,
        Star,
    },
})
export default class Photo extends Mixins(GlobalMixin) {
    private touchTimer = 0;

    @Prop() data: IPhoto;
    @Prop() day: IDay;

    @Emit('select') emitSelect(data: IPhoto) {}
    @Emit('click') emitClick() {}

    /** Get src for image to show */
    src() {
        if (this.data.flag & this.c.FLAG_PLACEHOLDER) {
            return undefined;
        } else if (this.data.flag & this.c.FLAG_LOAD_FAIL) {
            return errorsvg;
        } else {
            return this.url;
        }
    }

    /** Get url of the photo */
    get url() {
        let size = 256;
        if (this.data.w && this.data.h) {
            size = Math.floor(size * Math.max(this.data.w, this.data.h) / Math.min(this.data.w, this.data.h));
        }
        return getPreviewUrl(this.data.fileid, this.data.etag, false, size)
    }

    /** Error in loading image */
    error(e: any) {
        this.data.flag |= this.c.FLAG_LOAD_FAIL;
    }

    /** Clear timers */
    beforeUnmount() {
        clearTimeout(this.touchTimer);
    }

    toggleSelect() {
        if (this.data.flag & this.c.FLAG_PLACEHOLDER) return;
        this.emitSelect(this.data);
    }

    touchstart() {
        this.touchTimer = window.setTimeout(() => {
            this.toggleSelect();
            this.touchTimer = 0;
        }, 600);
    }

    contextmenu(e: Event) {
        e.preventDefault();
        e.stopPropagation();
    }

    touchend() {
        if (this.touchTimer) {
            clearTimeout(this.touchTimer);
            this.touchTimer = 0;
        }
    }
}
</script>

<style lang="scss" scoped>
/* Container and selection */
.p-outer {
    &.leaving {
        transition: all 0.2s ease-in;
        transform: scale(0.9);
        opacity: 0;
    }
}

// Distance of icon from border
$icon-dist: min(10px, 6%);

/* Extra icons */
.check-icon.select {
    position: absolute;
    top: $icon-dist; left: $icon-dist;
    z-index: 100;
    background-color: var(--color-main-background);
    border-radius: 50%;
    cursor: pointer;
    display: none;

    .p-outer:hover > & { display: flex; }
    .selected > & { display: flex; filter: invert(1); }
}
.video-icon, .star-icon {
    position: absolute;
    z-index: 100;
    pointer-events: none;
    filter: invert(1) brightness(100);
}
.video-icon {
    top: $icon-dist; right: $icon-dist;
}
.star-icon {
    bottom: $icon-dist; left: $icon-dist;
}

/* Actual image */
div.img-outer {
    padding: 2px;
    @media (max-width: 768px) { padding: 1px; }

    transition: padding 0.1s ease;
    background-clip: content-box, padding-box;
    background-color: var(--color-background-dark);

    .selected > & { padding: calc($icon-dist - 2px); }

    > img {
        background-clip: content-box;
        object-fit: cover;
        cursor: pointer;

        -webkit-tap-highlight-color: transparent;
        -webkit-touch-callout: none;
        user-select: none;
        transition: box-shadow 0.1s ease;

        .selected > & { box-shadow: 0 0 4px 2px var(--color-primary); }
        .p-outer.placeholder > & { display: none; }
        .p-outer.error & { object-fit: contain; }
    }
}
</style>