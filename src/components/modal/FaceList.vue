<template>
  <div class="outer">
    <div class="search">
      <NcTextField
        :autofocus="true"
        :value.sync="search"
        :label="t('memories', 'Search')"
        :placeholder="t('memories', 'Search')"
      >
        <Magnify :size="16" />
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

import * as dav from '../../services/DavRequests';
import Fuse from 'fuse.js';

import Magnify from 'vue-material-design-icons/Magnify.vue';

export default defineComponent({
  name: 'FaceList',
  components: {
    ClusterGrid,
    NcTextField,
    Magnify,
  },

  props: {
    plus: {
      type: Boolean,
      default: false,
    },
  },

  data: () => ({
    user: '',
    name: '',
    list: null as ICluster[] | null,
    fuse: null as Fuse<ICluster> | null,
    search: '',
  }),

  watch: {
    $route: async function (from: any, to: any) {
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
    close() {
      this.$emit('close');
    },

    async refreshParams() {
      this.user = <string>this.$route.params.user || '';
      this.name = <string>this.$route.params.name || '';
      this.list = null;
      this.search = '';

      this.list = (await dav.getFaceList(this.$route.name as any)).filter((c: IFace) => {
        const clusterName = String(c.name || c.cluster_id);
        return c.user_id === this.user && clusterName !== this.name;
      });

      this.fuse = new Fuse(this.list, { keys: ['name'] });
    },

    async addFace() {
      let name: string = '';

      try {
        // TODO: use a proper dialog
        name = window.prompt(this.t('memories', 'Enter name of the new face'), '') ?? '';
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
