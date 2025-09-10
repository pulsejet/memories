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
          class="star-filled"
          :class="{ show: star <= rating }"
          :size="size" 
        />
        <StarOutlineIcon 
          class="star-outline"
          :class="{ show: star > rating }"
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
    
    // In readonly mode, only show icons with "show" class
    :deep .star-filled,
    :deep .star-outline {
      display: none;
      
      &.show {
        display: inline-block;
      }
    }
    
    // Style filled stars
    :deep .star-filled.show {
      color: var(--color-warning);
    }
  }

  &.interactive {
    :deep .button-vue {
      transition: color 0.2s ease;
      
      &:hover {
        background-color: var(--color-background-hover) !important;
      }
    }

    // Default state (not hovering parent): only show icons with "show" class
    :deep .star-filled,
    :deep .star-outline {
      display: none;
      
      &.show {
        display: inline-block;
      }
    }
    
    :deep .star-filled.show {
      color: var(--color-warning);
    }
    
    // Hover state: when hovering the parent container, show hover preview
    &:hover {
      // Hide all icons with "show" class (current rating)
      :deep .star-filled.show,
      :deep .star-outline.show {
        display: none !important;
      }
      
      // Show outline stars by default during hover
      :deep .star-outline {
        display: inline-block !important;
      }
      
      :deep .star-filled {
        display: none !important;
      }
      
      // Show filled icons for hovered star and all following siblings
      :deep .button-vue:hover {
        .star-filled { 
          display: inline-block !important; 
          color: var(--color-warning);
        }
        .star-outline { 
          display: none !important; 
        }
      }
      
      // When hovering a star, also fill all following siblings (which are visually to the left)
      :deep .button-vue:hover ~ .button-vue {
        .star-filled { 
          display: inline-block !important; 
          color: var(--color-warning);
        }
        .star-outline { 
          display: none !important; 
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