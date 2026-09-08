<!--
  Memories sidebar.
  ================

  Two sidebars live here and are mutually exclusive:

  1. REDUCED sidebar (#app-sidebar-vue): Memories' own UI, renders the
     Metadata component directly for the current photo. Used for everything
     except explicit native-tab requests (see openNative).

  2. NATIVE shell (#app-sidebar-native): an NcAppSidebar hosting Files
     sidebar *tab web components* from the global tab registry
     (registerSidebarTab / getSidebarTabs from @nextcloud/files), e.g. the
     sharing tab. This is how Memories shows native tabs such as sharing.

  Background: Nextcloud 34 removed the globally mounted sidebar
  (OCA.Files.Sidebar). Its UI now only exists inside the Files app, and its
  store (getSidebar().open()) requires an active Files view and the Files
  router — neither exists on Memories routes. So the store is never driven
  from here; instead the tab components themselves are hosted directly.

  How a native open works (openNative):
  - The caller (viewer, share modal) first calls setTab(id), e.g. 'sharing'
    or 'memories-metadata'. Unknown ids fall back to reduced, see below.
  - The file is resolved via WebDAV stat into a full Node (permissions,
    mime, share attributes, ...) because tabs read all of that through
    their `node` prop. The tab scripts themselves (sharing, comments, ...)
    load on Memories pages because PageController dispatches the shared
    LoadSidebar event.
  - Tabs are filtered with their `enabled({ node, folder, view })` check
    and sorted by `order`, then the wanted (or first) tab is activated.
    Each tab's web component initializes lazily via onInit() exactly once,
    mirroring FilesSidebarTab in the Files app.
  - Sizing contract with the viewer: state is committed and a tick awaited
    BEFORE emitting memories:sidebar:opened/closed, so getWidth() measures
    the rendered shell and PhotoSwipe resizes beside it instead of being
    overlaid. The shell is a fixed 360px with transitions disabled.

  Custom-element setup: our own 'memories-metadata' tab wraps Metadata.vue
  via defineCustomElement. Its app installs globals, route checkers and the
  Memories router (same as the old native mount) so router-link based
  components such as album links resolve instead of crashing.

  Focus: NcAppSidebar traps focus while open. The viewer exempts both
  sidebar ids from PhotoSwipe's focus pull (see hasNestedTrap in Viewer),
  otherwise the two traps ping-pong until the call stack overflows.

  Failures (unregistered tab, unresolvable file, no enabled tabs, broken
  tab init) warn to the console and fall back to the reduced sidebar.
-->
<template>
  <aside id="app-sidebar-vue" class="app-sidebar reduced" v-if="reducedOpen">
    <div class="top-info" v-if="info">
      <div class="title">
        <h2>{{ info.basename }}</h2>

        <NcActions :inline="1">
          <NcActionButton :aria-label="t('memories', 'Close')" @click="close()">
            {{ t('memories', 'Close') }}
            <template #icon> <CloseIcon :size="20" /> </template>
          </NcActionButton>
        </NcActions>
      </div>

      <SidebarSubtitle :size="info.size" :mtime="info.mtime" />
    </div>

    <Metadata ref="metadata" />
  </aside>

  <NcAppSidebar
    v-if="nativeOpen"
    id="app-sidebar-native"
    :open="nativeOpen"
    :active="nativeTab?.id ?? ''"
    :name="nativeNode?.displayname ?? ''"
    no-toggle
    @close="close"
    @update:active="setTab"
  >
    <template #subname>
      <SidebarSubtitle :size="info?.size ?? 0" :mtime="info?.mtime ?? 0" />
    </template>
    <NcAppSidebarTab v-for="tab in availableTabs" :key="tab.id" :id="tab.id" :name="tab.displayName" :order="tab.order">
      <template #icon>
        <!-- eslint-disable-next-line vue/no-v-html -- SVG comes from the tab itself, same as Files app -->
        <span class="svg-icon" v-html="tab.iconSvgInline" />
      </template>
      <component
        v-if="readyTabs.has(tab.tagName)"
        :is="tab.tagName"
        :active.prop="nativeTab?.id === tab.id"
        :node.prop="nativeNode"
        :folder.prop="nativeFolder"
        :view.prop="nativeView"
      />
      <XLoadingIcon v-else />
    </NcAppSidebarTab>
  </NcAppSidebar>
</template>

<script lang="ts">
import { defineComponent, defineCustomElement } from 'vue';

import NcActions from '@nextcloud/vue/components/NcActions';
import NcActionButton from '@nextcloud/vue/components/NcActionButton';
import NcAppSidebar from '@nextcloud/vue/components/NcAppSidebar';
import NcAppSidebarTab from '@nextcloud/vue/components/NcAppSidebarTab';
import { File, Folder, getSidebarTabs, registerSidebarTab } from '@nextcloud/files';
import {
  getClient,
  getDefaultPropfind,
  getRemoteURL,
  getRootPath,
  registerDavProperty,
  resultToNode,
} from '@nextcloud/files/dav';
import { translate as t } from '@services/l10n';

import Metadata from '@components/Metadata.vue';
import SidebarSubtitle from '@components/SidebarSubtitle.vue';
import XLoadingIcon from '@components/XLoadingIcon.vue';
import { registerGlobals } from '../bootstrap';
import router, { registerRouteCheckers } from '../router';

import * as utils from '@services/utils';

import type { IImageInfo, IPhoto } from '@typings';
import type { IFolder, INode, ISidebarContext, ISidebarTab, IView } from '@nextcloud/files';
import type { FileStat, ResponseDataDetailed } from 'webdav';

import CloseIcon from 'vue-material-design-icons/Close.vue';
import InfoSvg from '@assets/info.svg';

const SIDEBAR_TAB_ID = 'memories-metadata';
const SIDEBAR_TAG_NAME = 'memories-files-sidebar-tab';

registerSidebarTab({
  id: SIDEBAR_TAB_ID,
  order: 50,
  displayName: t('memories', 'Info'),
  iconSvgInline: window.atob(InfoSvg.split(',')[1]), // base64 to svg
  enabled: () => true,
  tagName: SIDEBAR_TAG_NAME,
  async onInit() {
    if (window.customElements.get(SIDEBAR_TAG_NAME)) {
      // element already defined
      return;
    }
    const { default: MetadataTab } = await import('@components/Metadata.vue');
    window.customElements.define(
      SIDEBAR_TAG_NAME,
      defineCustomElement(MetadataTab, {
        configureApp: (app) => {
          registerGlobals(app);
          registerRouteCheckers(app);
          app.use(router);
        },
        shadowRoot: false,
      }),
    );
  },
});

export default defineComponent({
  name: 'Sidebar',
  components: {
    Metadata,
    NcActions,
    NcActionButton,
    NcAppSidebar,
    NcAppSidebarTab,
    CloseIcon,
    XLoadingIcon,
    SidebarSubtitle,
  },

  data: () => ({
    nativeOpen: false,
    reducedOpen: false,
    info: null as null | IImageInfo,
    lastKnownWidth: 0,
    pendingTab: null as string | null,
    nativeTab: null as ISidebarTab | null,
    nativeNode: null as INode | null,
    nativeFolder: null as IFolder | null,
    nativeView: null as IView | null,
    readyTabs: new Set<string>(),
    utils: Object.freeze(utils),
  }),

  computed: {
    /** Tabs from the global registry enabled for the current native node */
    availableTabs(): ISidebarTab[] {
      if (!this.nativeNode || !this.nativeFolder || !this.nativeView) return [];
      const context: ISidebarContext = {
        node: this.nativeNode,
        folder: this.nativeFolder,
        view: this.nativeView,
      };
      return getSidebarTabs()
        .filter((tab) => {
          try {
            return !tab.enabled || tab.enabled(context);
          } catch (e) {
            return false;
          }
        })
        .sort((a, b) => a.order - b.order);
    },
  },

  mounted() {
    utils.bus.on('memories:fragment:pop:sidebar', this.close);

    _m.sidebar = {
      open: this.open.bind(this),
      close: this.close.bind(this),
      isOpen: this.isOpen.bind(this),
      setTab: this.setTab.bind(this),
      invalidateUnless: this.invalidateUnless.bind(this),
      getWidth: this.getWidth.bind(this),
    };

    // Remove after https://github.com/nextcloud/server/pull/51077
    registerDavProperty('nc:share-attributes');
  },

  beforeUnmount() {
    utils.bus.off('memories:fragment:pop:sidebar', this.close);
  },

  methods: {
    refs() {
      return this.$refs as {
        metadata?: InstanceType<typeof Metadata>;
      };
    },

    async open(photo: IPhoto | number, filename?: string, useNative = false) {
      if ((!photo || useNative) && filename && (await this.openNative(photo, filename))) {
        return;
      }

      if (!photo) return;

      // Open reduced sidebar
      this.nativeOpen = false;
      this.nativeTab = null;
      this.reducedOpen = true;
      await this.$nextTick();

      // Update metadata compoenent
      this.info = (await this.refs().metadata?.update(photo)) ?? null;
      if (!this.info) return; // failure or state change
      this.handleOpen();
    },

    /**
     * Show a native sidebar tab for the given file.
     * @returns true if a native tab was shown
     */
    async openNative(photo: IPhoto | number, filename: string): Promise<boolean> {
      // Resolve the requested tab first to avoid a WebDAV roundtrip
      // when it isn't registered (falls back to reduced below).
      const wanted = this.pendingTab ?? this.nativeTab?.id;
      if (wanted && !getSidebarTabs().some((tab) => tab.id === wanted)) {
        console.warn(`Not showing native sidebar tab: '${wanted}' is not registered`);
        this.pendingTab = null;
        return false;
      }

      const fileid = typeof photo === 'number' ? photo : photo?.fileid;
      const photoObj = typeof photo === 'object' ? photo : undefined;

      // Resolve a full node via WebDAV so tabs get permissions, mime, etc.
      let node: INode | null = null;
      try {
        const res = (await getClient().stat(`${getRootPath()}${filename}`, {
          data: getDefaultPropfind(),
          details: true,
        })) as ResponseDataDetailed<FileStat>;
        node = resultToNode(res.data, getRootPath(), getRemoteURL());
      } catch (e) {
        // Fall back to a minimal node if the fileid is known
        if (!fileid) {
          console.warn('Not showing native sidebar tab: cannot resolve', filename);
          return false;
        }
        node = new File({
          source: `${getRemoteURL()}${getRootPath()}${filename}`,
          id: fileid,
          root: getRootPath(),
          owner: utils.uid,
          mime: photoObj?.mimetype,
          displayname: photoObj?.basename,
        });
      }

      const dir = filename.substring(0, filename.lastIndexOf('/')) || '/';
      const folder: IFolder = new Folder({
        source: `${getRemoteURL()}${getRootPath()}${dir}`,
        root: getRootPath(),
        owner: node.owner,
      });
      const view: IView = {
        id: 'memories',
        name: 'Memories',
        icon: '',
        getContents: async () => ({ contents: [], folder }),
      };

      this.nativeNode = node;
      this.nativeFolder = folder;
      this.nativeView = view;

      const tab =
        this.availableTabs.find((t) => t.id === wanted) ??
        this.availableTabs.find((t) => t.id === this.nativeTab?.id) ??
        this.availableTabs[0];
      if (!tab) {
        console.warn('Not showing native sidebar: no enabled tabs for', filename);
        this.nativeNode = null;
        this.nativeFolder = null;
        this.nativeView = null;
        return false;
      }

      this.nativeTab = tab;
      this.pendingTab = null;
      this.info = {
        basename: node.displayname,
        size: node.size ?? 0,
        mtime: node.mtime ? node.mtime.getTime() / 1000 : 0,
      } as IImageInfo;

      this.reducedOpen = false;
      this.nativeOpen = true;
      void this.initNativeTab(tab);
      // Wait for the shell to render so getWidth() measures correctly
      // and the viewer resizes instead of being overlaid.
      await this.$nextTick();
      this.handleOpen();
      return true;
    },

    /** Lazily initialize a tab's web component, mirroring the Files app */
    async initNativeTab(tab: ISidebarTab) {
      try {
        if (!window.customElements.get(tab.tagName)) {
          await tab.onInit?.();
          await window.customElements.whenDefined(tab.tagName);
        }
        if (this.nativeTab?.tagName === tab.tagName) {
          this.readyTabs.add(tab.tagName);
        }
      } catch (e) {
        if (window.customElements.get(tab.tagName) && this.nativeTab?.tagName === tab.tagName) {
          this.readyTabs.add(tab.tagName);
        } else {
          console.warn(`Failed to initialize native sidebar tab '${tab.id}': `, e);
        }
      }
    },

    async close() {
      if (this.nativeOpen || this.nativeTab) {
        this.nativeOpen = false;
        this.nativeTab = null;
        this.nativeNode = null;
        this.nativeFolder = null;
        this.nativeView = null;
        this.pendingTab = null;
        // Wait for teardown so getWidth() no longer measures the shell.
        await this.$nextTick();
      }
      if (this.reducedOpen) {
        this.reducedOpen = false;
        await this.$nextTick();
      }
      this.handleClose();
    },

    isOpen() {
      return this.reducedOpen || this.nativeOpen;
    },

    setTab(tab: string) {
      this.pendingTab = tab;
      if (this.nativeOpen) {
        const found = this.availableTabs.find((t) => t.id === tab);
        if (found) {
          this.nativeTab = found;
          void this.initNativeTab(found);
        } else {
          console.warn(`Not showing native sidebar tab: '${tab}' is not available`);
        }
      }
    },

    invalidateUnless(fileid: number) {
      this.refs().metadata?.invalidateUnless(fileid);
    },

    getWidth() {
      const sidebar = document.getElementById('app-sidebar-vue') ?? document.getElementById('app-sidebar-native');
      this.lastKnownWidth = sidebar?.offsetWidth || this.lastKnownWidth;
      return (this.lastKnownWidth || 2) - 2;
    },

    handleClose() {
      utils.bus.emit('memories:sidebar:closed', null);
      utils.fragment.pop(utils.fragment.types.sidebar);
    },

    handleOpen() {
      // Stop sidebar typing from leaking outside
      const sidebar = document.getElementById('app-sidebar-vue') ?? document.getElementById('app-sidebar-native');
      sidebar?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === 'Tab') {
          e.stopPropagation();
          return;
        }

        const element = e.target as HTMLElement;
        if (element.tagName === 'INPUT' || element.tagName === 'TEXTAREA' || element.isContentEditable) {
          e.stopPropagation();
          return;
        }
      });

      // Emit event
      utils.bus.emit('memories:sidebar:opened', null);

      // Use fragment navigation only on mobile
      if (utils.isMobile()) {
        utils.fragment.push(utils.fragment.types.sidebar);
      }
    },
  },
});
</script>

<style scoped lang="scss">
#app-sidebar-vue {
  position: fixed;
  top: 0;
  right: 0;
  width: 27vw;
  min-width: 300px;
  height: 100% !important;
  z-index: 2525;
  padding: 10px;
  box-sizing: border-box;
  background-color: var(--color-main-background);
  border-left: 1px solid var(--color-border);

  @media (max-width: 512px) {
    width: 100vw;
    min-width: unset;
  }

  .top-info {
    padding: 10px;
    padding-right: 0;
  }

  .title {
    display: flex;
    align-items: center;
    justify-content: space-between;

    h2 {
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      margin: 0;
    }
  }
}
</style>

<style lang="scss">
// Prevent sidebar from becoming too big
#app-sidebar-vue {
  max-width: 360px !important;
  position: fixed !important;

  &.reduced {
    overflow-y: auto;
  }

  @media (max-width: 512px) {
    max-width: unset !important;
  }
}

// Hosted native sidebar shell (see openNative)
#app-sidebar-native {
  position: fixed !important;
  top: 0 !important;
  right: 0 !important;
  width: 360px !important;
  max-width: 360px !important;
  height: 100% !important;
  z-index: 2525 !important;
  // No slide animation, appear instantly like the reduced sidebar
  transition: none !important;

  @media (max-width: 512px) {
    width: 100vw !important;
    max-width: unset !important;
  }

  .svg-icon svg {
    width: 20px;
    height: 20px;
    fill: currentColor;
  }
}

// Hack to put the floating dropdown menu above the
// sidebar ... this may have unintended side effects
.vs__dropdown-menu--floating {
  z-index: 2526;
}

// Make metadata tab scrollbar thin
#tab-memories-metadata,
.app-sidebar.reduced {
  scrollbar-width: thin;
  &::-webkit-scrollbar {
    width: 5px;
  }
  &::-webkit-scrollbar-track {
    background: transparent;
  }
}
</style>
