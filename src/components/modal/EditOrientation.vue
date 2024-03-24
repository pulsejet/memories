<template>
  <div class="edit-orientation" v-if="samples.length">
    {{
      t(
        'memories',
        'This feature rotates images losslessly by updating the EXIF metadata. This approach is known to sometimes not work correctly on certain image types such as HEIC. Make sure you do a test run before using it on multiple images.',
      )
    }}

    <div class="samples">
      <XImg v-for="src of samples" class="sample" :key="src" :src="src" :style="{ transform }" />
      <div class="sample more" v-if="photos.length > samples.length">
        <span>+{{ photos.length - samples.length }}</span>
      </div>
    </div>

    <NcActions :inline="3" class="actions">
      <NcActionButton
        :aria-label="t('memories', 'Rotate Left')"
        :title="t('memories', 'Rotate Left')"
        :disabled="disabled"
        @click="doleft"
      >
        <template #icon> <RotateLeftIcon :size="22" /> </template>
      </NcActionButton>
      <NcActionButton
        :aria-label="t('memories', 'Rotate Right')"
        :title="t('memories', 'Rotate Right')"
        :disabled="disabled"
        @click="doright"
      >
        <template #icon> <RotateRightIcon :size="22" /> </template>
      </NcActionButton>
      <NcActionButton
        :aria-label="t('memories', 'Flip')"
        :title="t('memories', 'Flip')"
        :disabled="disabled"
        @click="doflip"
      >
        <template #icon> <FlipHorizontalIcon :size="22" /> </template>
      </NcActionButton>
    </NcActions>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import * as utils from '@services/utils';

import NcActions from '@nextcloud/vue/dist/Components/NcActions.js';
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js';

import RotateLeftIcon from 'vue-material-design-icons/RotateLeft.vue';
import RotateRightIcon from 'vue-material-design-icons/RotateRight.vue';
import FlipHorizontalIcon from 'vue-material-design-icons/FlipHorizontal.vue';

import type { IPhoto } from '@typings';

const NORMAL = [1, 6, 3, 8];
const FLIPPED = [2, 7, 4, 5];

export default defineComponent({
  components: {
    NcActions,
    NcActionButton,
    RotateLeftIcon,
    RotateRightIcon,
    FlipHorizontalIcon,
  },

  props: {
    photos: {
      type: Array<IPhoto>,
      required: true,
    },
    disabled: {
      type: Boolean,
      default: false,
    },
  },

  emits: {
    save: () => true,
  },

  data: () => ({
    /** Current state relative to 1 */
    state: 1,
    /** Full (360) rotations for CSS transition */
    spins: 0,
  }),

  computed: {
    samples() {
      return this.photos.slice(0, 8).map((photo) => utils.getPreviewUrl({ photo, size: 512 }));
    },

    transform() {
      const f = this.isflip(this.state) ? -1 : 1;
      const d = this.spins;
      return `${this.transform1} rotate(${d * 360 * f}deg)`;
    },

    transform1(): string | null {
      if (this.disabled) return null;

      /**
       * 1 = Horizontal (normal)
       * 2 = Mirror horizontal
       * 3 = Rotate 180
       * 4 = Mirror vertical
       * 5 = Mirror horizontal and rotate 270 CW
       * 6 = Rotate 90 CW
       * 7 = Mirror horizontal and rotate 90 CW
       * 8 = Rotate 270 CW
       */
      if (this.state < 1 || this.state > 8) {
        console.error('Invalid orientation state', this.state);
        return null;
      }

      switch (this.state) {
        case 1:
          return 'rotate(0deg)';
        case 2:
          return 'scaleX(-1)';
        case 3:
          return 'rotate(180deg)';
        case 4:
          return 'scaleX(-1) rotate(-180deg)';
        case 5:
          return 'scaleX(-1) rotate(-270deg)';
        case 6:
          return 'rotate(90deg)';
        case 7:
          return 'scaleX(-1) rotate(-90deg)';
        case 8:
          return 'rotate(270deg)';
      }

      return null;
    },
  },

  methods: {
    /** Reset state to initial */
    reset() {
      this.state = 1;
      this.spins = 0;
    },

    /**
     * Get target orientation state for a photo.
     * If no change is needed, return null.
     */
    result(photo: IPhoto): number | null {
      const exif = photo.imageInfo?.exif;
      if (!exif) return null;

      let state = Number(exif.Orientation) || 1;
      const oldState = state;

      // Check if state is valid
      if (state < 1 || state > 8) {
        state = 1;
      }

      // Flip state if needed
      if (this.isflip(this.state)) {
        state = this.flip(state);
      }

      // Rotate state by index difference
      const cindex = this.list(this.state).indexOf(this.state);
      const list = this.list(state);
      const sindex = list.indexOf(state);
      state = list[(cindex + sindex) % list.length];

      // No change
      if ((!exif.Orientation && state === 1) || state === oldState) {
        return null;
      }

      return state;
    },

    doleft() {
      const list = this.list(this.state);
      let index = list.indexOf(this.state) - 1;
      if (index < 0) {
        this.spins--;
        index = list.length - 1;
      }
      this.state = list[index];
    },

    doright() {
      const list = this.list(this.state);
      let index = list.indexOf(this.state) + 1;
      if (index === list.length) {
        this.spins++;
        index = 0;
      }
      this.state = list[index];
    },

    doflip() {
      this.state = this.flip(this.state);
    },

    /** Flip a state in-place */
    flip(state: number) {
      if (this.isflip(state)) {
        let i = FLIPPED.indexOf(state);
        if (i === 1) i = 3;
        else if (i === 3) i = 1;
        return NORMAL[i];
      } else {
        let i = NORMAL.indexOf(state);
        if (i === 1) i = 3;
        else if (i === 3) i = 1;
        return FLIPPED[i];
      }
    },

    /** Check if a state is flipped */
    isflip(state: number) {
      return FLIPPED.includes(state);
    },

    /** Get rotation list for this state (flipped / regular) */
    list(state: number) {
      return this.isflip(state) ? FLIPPED : NORMAL;
    },
  },
});
</script>

<style scoped lang="scss">
.edit-orientation {
  margin: 4px 0;

  .samples {
    display: grid;
    grid-gap: 5px;
    grid-template-columns: repeat(auto-fit, 80px);
    justify-content: center;
    margin: 6px 0;
    margin-top: 10px;

    .sample {
      border-radius: 10px;
      object-fit: cover;
      width: 100%;
      aspect-ratio: 1 / 1;
      transition: transform 0.2s ease-in-out;

      &:first-child {
        grid-column: span 2;
        grid-row: span 2;
      }
    }

    .more {
      display: flex;
      justify-content: center;
      align-items: center;
      background-color: var(--color-background-dark);

      > span {
        font-size: 1.3em;
        font-weight: 500;
        transform: translate(-3px, -3px);
      }
    }
  }

  .actions {
    justify-content: center;
  }
}
</style>
