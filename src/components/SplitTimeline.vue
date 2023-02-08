<template>
  <div class="container">
    <div class="primary">
      <component :is="primary" />
    </div>
    <div class="timeline">
      <Timeline />
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent } from "vue";
import Timeline from "./Timeline.vue";
import LocationTopMatter from "./top-matter/LocationTopMatter.vue";

export default defineComponent({
  name: "SplitTimeline",

  components: {
    Timeline,
  },

  computed: {
    primary() {
      switch (this.$route.name) {
        case "locations":
          return LocationTopMatter;
        default:
          return "None";
      }
    },
  },
});
</script>

<style lang="scss" scoped>
.container {
  width: 100%;
  height: 100%;
  display: flex;
  overflow: hidden;
}
.primary {
  width: 60%;
  height: 100%;
}
.timeline {
  flex: 1;
  height: 100%;
  padding-left: 8px;
}

@media (max-width: 768px) {
  .container {
    flex-direction: column;
  }
  .primary {
    width: 100%;
    height: 40%;
  }
  .timeline {
    width: 100%;
    height: 60%;
    padding-left: 0;
  }
}
</style>
