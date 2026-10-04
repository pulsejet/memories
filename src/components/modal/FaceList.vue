<template>
  <div class="outer">
    <div class="search">
      <NcTextField
        :autofocus="true"
        v-model="search"
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
import { defineComponent, defineAsyncComponent, markRaw } from 'vue';
import Fuse from 'fuse.js';

import { showError } from '@nextcloud/dialogs';

const NcTextField = defineAsyncComponent(() => import('@nextcloud/vue/components/NcTextField'));

import ClusterGrid from '@components/ClusterGrid.vue';

import * as dav from '@services/dav';
import * as utils from '@services/utils';

import type { ICluster, IFace } from '@typings';

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
    list: null as IFace[] | null,
    fuse: null as Fuse<IFace> | null,
    search: String(),
  }),

  mounted() {
    this.refresh();
  },

  computed: {
    user() {
      return this.$route.params.user?.toString();
    },

    name() {
      return this.$route.params.name?.toString();
    },

    backend() {
      return this.$route.name as 'recognize' | 'facerecognition' | 'lens';
    },

    filteredList() {
      if (!this.list || !this.search || !this.fuse) return this.list ?? [];
      return this.fuse.search(this.search).map((r) => r.item);
    },
  },

  methods: {
    async refresh() {
      try {
        this.list = null;
        const faces = await dav.getFaceList(this.backend);
        this.list = faces.filter((c: IFace) => c.user_id === this.user && String(c.name || c.cluster_id) !== this.name);
        this.fuse = markRaw(new Fuse(this.list, { keys: ['name'] }));
      } catch (e) {
        showError(this.t('memories', 'Failed to load faces'));
        console.error(e);
      }
    },

    async addFace() {
      if (this.backend === 'lens') {
        return this.addLensPerson();
      }

      let name = String();

      try {
        const input = await utils.prompt({
          message: this.t('memories', 'Create a new face with this name?'),
          title: this.t('memories', 'Create new face'),
          name: this.t('memories', 'Name'),
        });

        name = input?.trim() ?? String();
        if (!name) return;

        // Create new directory in WebDAV
        await dav.recognizeCreateFace(this.user, name);

        return this.selectNew(name);
      } catch (e: any) {
        // Directory already exists
        if (e.status === 405) return this.selectNew(name);

        showError(this.t('memories', 'Failed to create face'));
      }
    },

    async addLensPerson() {
      let name: string | null;
      try {
        name = await utils.prompt({
          message: this.t('memories', 'Create a new person with this name?'),
          title: this.t('memories', 'Create new person'),
          name: this.t('memories', 'Name'),
        });
      } catch {
        return;
      }
      if (name === null) return;

      // The id is minted server-side; the cluster is created by this call
      // and the faces join it through the move.
      try {
        const person = await dav.lensCreatePerson(name.trim());
        this.$emit('select', {
          cluster_id: person.id,
          cluster_type: 'lens',
          count: 0,
          name: person.name,
          user_id: this.user,
        });
      } catch {
        showError(this.t('memories', 'Failed to create person'));
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

    click(item: ICluster) {
      this.$emit('select', item as IFace);
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
