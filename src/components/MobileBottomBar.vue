<template>
  <div class="mobile-bottom-bar" :class="{ 'mobile-bottom-bar--dark': dark }">
    <slot />
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

export default defineComponent({
  name: 'MobileBottomBar',

  props: {
    dark: {
      type: Boolean,
      default: false,
    },
  },
});
</script>

<style lang="scss" scoped>
.mobile-bottom-bar {
  display: flex;
  box-sizing: border-box;
  text-align: center;
  padding: 8px;
  padding-top: 3px;
  font-size: 0.9em;
  overflow: hidden;

  :deep(.mobile-bottom-bar-item) {
    flex: 1 1 0px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: flex-start;
    background: transparent;
    border: none;
    color: inherit;
    font: inherit;
    font-size: inherit;
    text-decoration: none;
    opacity: 0.75;
    cursor: pointer;
    padding: 0;
    min-width: 0;

    span.material-design-icon {
      border-radius: 20px;
      padding: 4px;
      max-width: 70px;
      margin: 0 auto;
    }

    .label {
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      max-width: 100%;
      line-height: 1.2;
    }

    &.router-link-exact-active,
    &.active {
      opacity: 1;

      span.material-design-icon {
        background: var(--color-primary-element-light);
      }
    }
  }

  &--dark {
    color: white;

    :deep(.mobile-bottom-bar-item) {
      opacity: 0.92;

      &:hover,
      &:focus,
      &:active,
      &:focus-visible {
        opacity: 1;
        background-color: transparent !important;
        color: inherit !important;
        border-color: transparent;
        box-shadow: none !important;
        outline: none !important;
      }

      &.router-link-exact-active,
      &.active {
        span.material-design-icon {
          background: rgba(255, 255, 255, 0.2);
        }
      }
    }
  }
}
</style>
