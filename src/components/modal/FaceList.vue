<template>
  <div class="outer">
    <div class="search">
      <NcTextField
        :autofocus="true"
        :value.sync="search"
        :label="t('memories', 'Search')"
        :placeholder="t('memories', 'Search')"
      >
        <MagnifyIcon :size="16" />
      </NcTextField>
    </div>

    <ClusterGrid
      v-if="list"
      :items="filteredList"
      :maxSize="120"
      :link="false"
      :plus="plus"
      @click="click"
      @plus="addFace"
    />
    <div v-else>
      {{ t('memories', 'Loading …') }}
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';
import { ICluster, IFace } from '../../types';
import ClusterGrid from '../ClusterGrid.vue';

import { showError } from '@nextcloud/dialogs';
const NcTextField = () => import('@nextcloud/vue/dist/Components/NcTextField');

import * as dav from '../../services/dav';
import Fuse from 'fuse.js';

import MagnifyIcon from 'vue-material-design-icons/Magnify.vue';

export default defineComponent({
  name: 'FaceList',
  components: {
    ClusterGrid,
    NcTextField,
    MagnifyIcon,
  },

  props: {
    plus: {
      type: Boolean,
      default: false,
    },
  },

  emits: {
    select: (face: IFace) => true,
  },

  data: () => ({
    user: String(),
    name: String(),
    list: null as ICluster[] | null,
    fuse: null as Fuse<ICluster> | null,
    search: String(),
  }),

  watch: {
    $route() {
      this.refreshParams();
    },
  },

  mounted() {
    this.refreshParams();
  },

  computed: {
    filteredList() {
      if (!this.list || !this.search || !this.fuse) return this.list || [];
      return this.fuse.search(this.search).map((r) => r.item);
    },
  },

  methods: {
    async refreshParams() {
      this.user = this.$route.params.user;
      this.name = this.$route.params.name;
      this.list = null;
      this.search = '';

      const backend = this.routeIsRecognize ? 'recognize' : this.routeIsFaceRecognition ? 'facerecognition' : null;
      console.assert(backend, '[BUG] Invalid route for FaceList');

      const faces = await dav.getFaceList(backend!);
      this.list = faces.filter((c: IFace) => c.user_id === this.user && String(c.name || c.cluster_id) !== this.name);

      this.fuse = new Fuse(this.list, { keys: ['name'] });
    },

    async addFace() {
      let name: string = String();

      try {
        // TODO: use a proper dialog
        name = window.prompt(this.t('memories', 'Enter name of the new face'), String()) ?? String();
        if (!name) return;

        // Create new directory in WebDAV
        await dav.recognizeCreateFace(this.user, name);

        return this.selectNew(name);
      } catch (e) {
        // Directory already exists
        if (e.status === 405) return this.selectNew(name);

        showError(this.t('memories', 'Failed to create face'));
      }
    },

    selectNew(name: string) {
      this.$emit('select', {
        cluster_id: name,
        cluster_type: 'recognize',
        count: 0,
        name: name,
        user_id: this.user,
      });
    },

    click(face: IFace) {
      this.$emit('select', face);
    },
  },
});
</script>

<style lang="scss" scoped>
.outer {
  width: 100%;
  max-height: calc(90vh - 80px - 4em);
  overflow: hidden;
  display: flex;
  flex-direction: column;

  .search {
    margin-bottom: 10px;
  }
}
</style>
