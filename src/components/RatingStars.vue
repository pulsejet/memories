<template>
  <NcActions :inline="5" class="rating-stars" :class="{ readonly, interactive: !readonly }">
    <NcActionButton
      v-for="star in [5, 4, 3, 2, 1]"
      :key="star"
      :class="{ filled: star <= rating }"
      :data-star="star"
      :aria-label="readonly ? '' : t('memories', 'Rate {star} stars', { star })"
      @click="!readonly && setRating(star)"
      :disabled="readonly"
    >
      <template #icon>
        <StarIcon 
          v-if="star <= rating"
          :size="size" 
        />
        <StarOutlineIcon 
          v-else
          :size="size" 
        />
      </template>
    </NcActionButton>
  </NcActions>
</template>

<script>
import NcActions from '@nextcloud/vue/dist/Components/NcActions.js';
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js';
import StarIcon from 'vue-material-design-icons/Star.vue';
import StarOutlineIcon from 'vue-material-design-icons/StarOutline.vue';
import { translate as t } from '@services/l10n';

export default {
  name: 'RatingStars',
  
  components: {
    NcActions,
    NcActionButton,
    StarIcon,
    StarOutlineIcon,
  },

  props: {
    /** The current rating value (0-5) */
    rating: {
      type: Number,
      default: 0,
      validator: (value) => value >= 0 && value <= 5,
    },
    /** Whether the rating is readonly (display only) */
    readonly: {
      type: Boolean,
      default: false,
    },
    /** Size of the stars */
    size: {
      type: Number,
      default: 20,
    },
  },

  methods: {
    setRating(star) {
      if (this.readonly) return;
      this.$emit('update:rating', star);
    },

    t(app, text, vars) {
      return t('memories', text, vars);
    },
  },
};
</script>

<style lang="scss" scoped>
.rating-stars {
  display: flex;
  align-items: center;
  gap: 0 !important;
  flex-direction: row-reverse;

  &.readonly {
    pointer-events: none;
    
    :deep .button-vue {
      cursor: default;
      background-color: transparent !important;
      
      &:hover {
        background-color: transparent !important;
      }
    }
    
    // Filled stars: yellow/warning color
    :deep .material-design-icon.star-icon {
      color: var(--color-warning);
    }
    
    // Outline stars: inherit color (white on dark, dark on light)
    :deep .material-design-icon.star-outline-icon {
      color: currentColor;
      opacity: 0.7;
    }
  }

  &.interactive {
    :deep .button-vue {
      transition: color 0.2s ease;
      
      &:hover {
        background-color: var(--color-background-hover) !important;
      }
    }

    // Default state (not hovering): show current rating
    // Filled stars: yellow/warning color
    :deep .material-design-icon.star-icon {
      color: var(--color-warning);
    }
    
    // Outline stars: inherit color (adapts to theme/context)
    :deep .material-design-icon.star-outline-icon {
      color: currentColor;
      opacity: 0.7;
    }
    
    // Hover state: when hovering the parent container, show hover preview
    &:hover {
      // During hover, show all stars in preview mode
      // All stars become yellow when hovered or after hovered star
      :deep .button-vue {
        .material-design-icon {
          color: currentColor;
          opacity: 0.7;
        }
      }
      
      // Hovered star and following siblings (visually to the left) become filled/yellow
      :deep .button-vue:hover,
      :deep .button-vue:hover ~ .button-vue {
        .material-design-icon {
          color: var(--color-warning) !important;
          opacity: 1 !important;
        }
      }
    }
  }

  :deep .button-vue {
    background-color: transparent !important;
    min-height: unset;
    min-width: unset;
    width: auto;
    height: auto;
    padding: 4px;
    margin: 0;
    border-radius: var(--border-radius);
    
    .button-vue__wrapper {
      padding: 0;
    }
  }
}
</style> 