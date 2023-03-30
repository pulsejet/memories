<template>
  <div class="split-container">
    <div class="primary" ref="primary">
      <component :is="primary" />
    </div>

    <div
      class="separator"
      ref="separator"
      @pointerdown="sepDown"
      @touchmove.passive="sepTouchMove"
      @touchend.passive="pointerUp"
      @touchcancel.passive="pointerUp"
    ></div>

    <div class="timeline">
      <Timeline />
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent } from "vue";
import Timeline from "./Timeline.vue";
const MapSplitMatter = () => import("./top-matter/MapSplitMatter.vue");
import { emit } from "@nextcloud/event-bus";

export default defineComponent({
  name: "SplitTimeline",

  components: {
    Timeline,
  },

  data: () => ({
    pointerDown: false,
    primaryPos: 0,
  }),

  computed: {
    primary() {
      switch (this.$route.name) {
        case "map":
          return MapSplitMatter;
        default:
          return "None";
      }
    },
  },

  beforeDestroy() {
    this.pointerUp();
  },

  methods: {
    isVertical() {
      return globalThis.windowInnerWidth <= 768;
    },

    sepDown(event: PointerEvent) {
      this.pointerDown = true;

      // Get position of primary element
      const primary = <HTMLDivElement>this.$refs.primary;
      const rect = primary.getBoundingClientRect();
      this.primaryPos = this.isVertical() ? rect.top : rect.left;

      // Let touch handle itself
      if (event.pointerType === "touch") return;

      // Otherwise, handle pointer events on document
      document.addEventListener("pointermove", this.documentPointerMove);
      document.addEventListener("pointerup", this.pointerUp);

      // Prevent text selection
      event.preventDefault();
      event.stopPropagation();
    },

    sepTouchMove(event: TouchEvent) {
      if (!this.pointerDown) return;
      this.setFlexBasis(event.touches[0]);
    },

    documentPointerMove(event: PointerEvent) {
      if (!this.pointerDown || !event.buttons) return this.pointerUp();
      this.setFlexBasis(event);
    },

    pointerUp() {
      // Get rid of listeners on document quickly
      this.pointerDown = false;
      document.removeEventListener("pointermove", this.documentPointerMove);
      document.removeEventListener("pointerup", this.pointerUp);
      emit("memories:window:resize", null);
    },

    setFlexBasis(pos: { clientX: number; clientY: number }) {
      const ref = this.isVertical() ? pos.clientY : pos.clientX;
      const newWidth = Math.max(ref - this.primaryPos, 50);
      (<HTMLDivElement>this.$refs.primary).style.flexBasis = `${newWidth}px`;
    },
  },
});
</script>

<style lang="scss" scoped>
.split-container {
  width: 100%;
  height: 100%;
  display: flex;
  overflow: hidden;

  > div {
    height: 100%;
    max-height: 100%;
  }

  > .primary {
    flex-basis: 60%;
    flex-shrink: 0;
  }

  > .timeline {
    flex-basis: auto;
    flex-grow: 1;
    padding-left: 8px;
    overflow: hidden;
  }

  > .separator {
    flex-grow: 0;
    flex-shrink: 0;
    width: 5px;
    background-color: gray;
    opacity: 0.1;
    cursor: col-resize;
    margin: 0 0 0 auto;
    transition: opacity 0.4s ease-out, background-color 0.4s ease-out;
  }

  > .separator:hover {
    opacity: 0.4;
    background-color: var(--color-primary);
  }
}

@media (max-width: 768px) {
  .split-container {
    flex-direction: column;

    > div {
      width: 100%;
      height: unset;
    }

    > .primary {
      flex-basis: 40%;
    }

    > .timeline {
      padding-left: 0;
    }

    > .separator {
      height: 5px;
      width: 100%;
    }
  }
}
</style>
