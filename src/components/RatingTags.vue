<template>
  <div class="rating-tags" :class="{ compact, slideshow }">
    <!-- Rating -->
    <div v-if="rating && rating > 0" class="rating-section">
      <RatingStars 
        :rating="rating" 
        :size="starSize" 
        readonly 
      />
    </div>
    
    <!-- Tags -->
    <div v-if="tags.length > 0" class="tags-section">
      <div class="tags-container">
        <NcChip 
          v-for="(tag, idx) in displayTags"
          :key="`tag-${idx}`"
          :text="truncateTagPath(tag.join(' → '))" 
          no-close 
          :size="compact ? 'small' : 'medium'"
        />
        
        <span v-if="hasMoreTags" class="more-tags">
          +{{ tags.length - maxTags }}
        </span>
      </div>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';
import type { PropType } from 'vue';

import RatingStars from './RatingStars.vue';
import NcChip from '@nextcloud/vue/dist/Components/NcChip.js';

export default defineComponent({
  name: 'RatingTags',
  
  components: {
    RatingStars,
    NcChip,
  },

  props: {
    /** Photo rating (0-5) */
    rating: {
      type: Number,
      default: 0,
    },
    
    /** Array of tag arrays (hierarchical tags) */
    tags: {
      type: Array as PropType<string[][]>,
      default: () => [],
    },
    
    /** Compact display mode */
    compact: {
      type: Boolean,
      default: false,
    },
    
    /** Slideshow mode styling */
    slideshow: {
      type: Boolean,
      default: false,
    },
    
    /** Maximum number of tags to show before truncating */
    maxTags: {
      type: Number,
      default: 5,
    },
  },

  computed: {
    /** Star size based on display mode */
    starSize(): number {
      if (this.compact) return 16;
      if (this.slideshow) return 18;
      return 20;
    },
    
    /** Tags to display (limited by maxTags) */
    displayTags(): string[][] {
      return this.tags.slice(0, this.maxTags);
    },
    
    /** Whether there are more tags than displayed */
    hasMoreTags(): boolean {
      return this.tags.length > this.maxTags;
    },
  },

  methods: {
    /**
     * Truncate tag path if longer than 200 characters
     * Shows ellipsis at the beginning, not the end
     */
    truncateTagPath(path: string): string {
      if (path.length <= 200) {
        return path;
      }
      
      const truncated = path.substring(path.length - 150);
      return '…' + truncated;
    },
  },
});
</script>

<style lang="scss" scoped>
.rating-tags {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;

  &.compact {
    gap: 8px;
    font-size: 0.9em;
  }

  &.slideshow {
    color: white;
    
    .tags-section {
      :deep .chip {
        background-color: rgba(255, 255, 255, 0.2);
        color: white;
        backdrop-filter: blur(4px);
      }
    }
    
    .more-tags {
      color: rgba(255, 255, 255, 0.8);
    }
  }
}

.rating-section {
  display: flex;
  align-items: center;
}

.tags-section {
  display: flex;
  align-items: center;
  min-width: 0; // Allow flex shrinking
}

.tags-container {
  display: flex;
  align-items: center;
  gap: 4px;
  flex-wrap: wrap;

  :deep .chip {
    margin: 0;
    font-size: inherit;
  }
}

.more-tags {
  font-size: 0.85em;
  color: var(--color-text-lighter);
  margin-left: 4px;
  white-space: nowrap;
}



// Responsive adjustments
@media (max-width: 768px) {
  .rating-tags {
    &:not(.compact) {
      gap: 8px;
      font-size: 0.9em;
    }
  }
  
  .tags-container {
    gap: 2px;
  }
}
</style>
