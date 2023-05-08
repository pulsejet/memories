<template>
  <div class="loading-icon">
    <div class="higher">
      <div class="stage">
        <div class="dot-pulse"></div>
      </div>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

export default defineComponent({
  name: 'XLoadingIcon',
});
</script>

<style lang="scss">
.loading-icon.centered,
.loading-icon > div > div.stage {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
}
</style>

<style lang="scss" scoped>
// https://codepen.io/nzbin/pen/GGrXbp

.loading-icon {
  z-index: 100000;

  .higher {
    position: relative;
    height: 100%;
    width: 100%;
    min-height: inherit;
  }

  .dot-pulse {
    position: relative;
    left: -9999px;
    box-shadow: 9999px 0 0 -5px;
    animation: dot-pulse 1.5s infinite linear;
    animation-delay: 0.25s;
  }
  .dot-pulse,
  .dot-pulse::after,
  .dot-pulse::before {
    width: 10px;
    height: 10px;
    border-radius: 5px;
    background-color: var(--color-loading-light);
    color: var(--color-loading-light);
  }
  .dot-pulse::before,
  .dot-pulse::after {
    content: '';
    display: inline-block;
    position: absolute;
    top: 0;
  }
  .dot-pulse::before {
    box-shadow: 9984px 0 0 -5px;
    animation: dot-pulse-before 1.5s infinite linear;
    animation-delay: 0s;
  }
  .dot-pulse::after {
    box-shadow: 10014px 0 0 -5px;
    animation: dot-pulse-after 1.5s infinite linear;
    animation-delay: 0.5s;
  }

  @keyframes dot-pulse-before {
    0% {
      box-shadow: 9984px 0 0 -5px;
    }
    30% {
      box-shadow: 9984px 0 0 2px;
    }
    60%,
    100% {
      box-shadow: 9984px 0 0 -5px;
    }
  }
  @keyframes dot-pulse {
    0% {
      box-shadow: 9999px 0 0 -5px;
    }
    30% {
      box-shadow: 9999px 0 0 2px;
    }
    60%,
    100% {
      box-shadow: 9999px 0 0 -5px;
    }
  }
  @keyframes dot-pulse-after {
    0% {
      box-shadow: 10014px 0 0 -5px;
    }
    30% {
      box-shadow: 10014px 0 0 2px;
    }
    60%,
    100% {
      box-shadow: 10014px 0 0 -5px;
    }
  }
}
</style>
