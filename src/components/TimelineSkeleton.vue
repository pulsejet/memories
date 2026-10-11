<template>
  <div class="timeline-skeleton" aria-hidden="true">
    <div v-for="row in rows" :key="row.id">
      <div class="sk-head">
        <div class="sk-bar" :style="{ width: `${row.headWidth}px` }" />
      </div>
      <div class="sk-photos">
        <div
          v-for="(width, i) in row.widths"
          :key="i"
          class="sk-photo"
          :style="{
            flexGrow: width,
            animationDelay: `${(row.id * 0.12 + i * 0.05).toFixed(2)}s`,
          }"
        />
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
defineOptions({
  name: 'TimelineSkeleton',
});

const NUM_ROWS = 10;

const rows = Array.from({ length: NUM_ROWS }, (_, r) => {
  const count = 3 + (r % 3);
  const widths = Array.from({ length: count }, (_, c) => 0.8 + ((r * 7 + c * 13) % 10) / 10);
  return {
    id: r,
    widths,
    headWidth: 100 + ((r * 37) % 80),
  };
});
</script>

<style lang="scss" scoped>
.timeline-skeleton {
  flex: 1;
  min-height: 0;
  overflow: hidden;
  padding: 4px 40px 0 0;
  pointer-events: none;
  user-select: none;

  @media (max-width: 768px) {
    padding-right: 0;
  }

  .sk-head {
    display: flex;
    align-items: center;
    height: 40px;
    padding-left: 3px;
  }

  .sk-photos {
    display: flex;
    gap: 4px;
    height: 200px;
  }

  .sk-photo {
    flex-basis: 0;
    min-width: 0;
    border-radius: 3px;
  }

  .sk-bar,
  .sk-photo {
    background-color: var(--color-background-dark);
    animation: timeline-skeleton-pulse 1.6s ease-in-out infinite;
  }

  .sk-bar {
    height: 16px;
    border-radius: 8px;
  }

  // Square grid like the timeline on mobile (cf. isMobileLayout)
  @media (max-width: 600px) {
    .sk-photos {
      display: grid;
      height: auto;
      grid-template-columns: repeat(3, 1fr);
      gap: 2px;
    }

    .sk-photo {
      aspect-ratio: 1;

      &:nth-child(n + 4) {
        display: none;
      }
    }
  }
}

@keyframes timeline-skeleton-pulse {
  50% {
    opacity: 0.45;
  }
}
</style>
