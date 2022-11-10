<template>
  <div ref="editor" class="viewer__image-editor" v-bind="themeDataAttr" />
</template>

<script lang="ts">
import { Component, Prop, Mixins } from "vue-property-decorator";
import GlobalMixin from "../mixins/GlobalMixin";

import { basename, dirname, extname, join } from "path";
import { emit } from "@nextcloud/event-bus";
import { showError, showSuccess } from "@nextcloud/dialogs";
import { generateUrl } from "@nextcloud/router";
import axios from "@nextcloud/axios";

import FilerobotImageEditor from "filerobot-image-editor";
import { FilerobotImageEditorConfig } from "react-filerobot-image-editor";

import translations from "./ImageEditorTranslations";

const { TABS, TOOLS } = FilerobotImageEditor as any;

@Component({
  components: {},
})
export default class ImageEditor extends Mixins(GlobalMixin) {
  @Prop() fileid: number;
  @Prop() mime: string;
  @Prop() src: string;

  private exif: any = null;

  private imageEditor: FilerobotImageEditor = null;

  get config(): FilerobotImageEditorConfig & { theme: any } {
    let src: string;
    if (["image/png", "image/jpeg", "image/webp"].includes(this.mime)) {
      src = this.src;
    } else {
      src = generateUrl("/apps/memories/api/image/jpeg/{fileid}", {
        fileid: this.fileid,
      });
    }

    return {
      source: src,

      defaultSavedImageName: this.defaultSavedImageName,
      defaultSavedImageType: this.defaultSavedImageType,
      // We use our own translations
      useBackendTranslations: false,

      // Watch resize
      observePluginContainerSize: true,

      // Default tab and tool
      defaultTabId: TABS.ADJUST,
      defaultToolId: TOOLS.CROP,

      // Displayed tabs, disabling watermark
      tabsIds: Object.values(TABS)
        .filter((tab) => tab !== TABS.WATERMARK)
        .sort((a: string, b: string) => a.localeCompare(b)) as any[],

      // onBeforeSave: this.onBeforeSave,
      onClose: this.onClose,
      // onModify: this.onModify,
      onSave: this.onSave,

      Rotate: {
        angle: 90,
        componentType: "buttons",
      },

      // Translations
      translations,

      theme: {
        palette: {
          "bg-secondary": "var(--color-main-background)",
          "bg-primary": "var(--color-background-dark)",
          // Accent
          "accent-primary": "var(--color-primary)",
          // Use by the slider
          "border-active-bottom": "var(--color-primary)",
          "icons-primary": "var(--color-main-text)",
          // Active state
          "bg-primary-active": "var(--color-background-dark)",
          "bg-primary-hover": "var(--color-background-hover)",
          "accent-primary-active": "var(--color-main-text)",
          // Used by the save button
          "accent-primary-hover": "var(--color-primary)",

          warning: "var(--color-error)",
        },
        typography: {
          fontFamily: "var(--font-face)",
        },
      },

      savingPixelRatio: 8,
      previewPixelRatio: window.devicePixelRatio,
    };
  }

  get defaultSavedImageName() {
    return basename(this.src, extname(this.src));
  }

  get defaultSavedImageType(): "jpeg" | "png" | "webp" {
    const mime = extname(this.src).slice(1);
    if (["jpeg", "png", "webp"].includes(mime)) {
      return mime as any;
    }
    return "jpeg";
  }

  get hasHighContrastEnabled() {
    const themes = globalThis.OCA?.Theming?.enabledThemes || [];
    return themes.find((theme) => theme.indexOf("highcontrast") !== -1);
  }

  get themeDataAttr() {
    if (this.hasHighContrastEnabled) {
      return {
        "data-theme-dark-highcontrast": true,
      };
    }
    return {
      "data-theme-dark": true,
    };
  }

  async mounted() {
    this.imageEditor = new FilerobotImageEditor(
      <any>this.$refs.editor,
      <any>this.config
    );
    this.imageEditor.render();
    window.addEventListener("keydown", this.handleKeydown, true);
    window.addEventListener("DOMNodeInserted", this.handleSfxModal);

    // Get latest exif data
    try {
      const res = await axios.get(
        generateUrl("/apps/memories/api/image/info/{id}?basic=1&current=1", {
          id: this.fileid,
        })
      );

      this.exif = res.data?.current;
      if (!this.exif) {
        throw new Error("No exif data");
      }
    } catch (err) {
      console.error(err);
      alert(
        this.t("memories", "Failed to get Exif data. Metadata may be lost!")
      );
    }
  }

  beforeDestroy() {
    if (this.imageEditor) {
      this.imageEditor.terminate();
    }
    window.removeEventListener("keydown", this.handleKeydown, true);
    window.removeEventListener("DOMNodeInserted", this.handleSfxModal);
  }

  onClose(closingReason, haveNotSavedChanges) {
    if (haveNotSavedChanges) {
      this.onExitWithoutSaving();
      return;
    }
    window.removeEventListener("keydown", this.handleKeydown, true);
    this.$emit("close");
  }

  /**
   * User saved the image
   *
   * @see https://github.com/scaleflex/filerobot-image-editor#onsave
   */
  async onSave({
    fullName,
    imageBase64,
  }: {
    fullName?: string;
    imageBase64?: string;
  }): Promise<void> {
    if (!imageBase64) {
      throw new Error("No image data");
    }

    const { origin, pathname } = new URL(this.src);
    const putUrl = origin + join(dirname(pathname), fullName);

    if (
      !this.exif &&
      !confirm(this.t("memories", "No Exif data found! Continue?"))
    ) {
      return;
    }

    try {
      const blob = await fetch(imageBase64).then((res) => res.blob());
      const response = await axios.put(putUrl, new File([blob], fullName));
      const fileid =
        parseInt(response?.headers?.["oc-fileid"]?.split("oc")[0]) || null;
      if (response.status >= 400) {
        throw new Error("Failed to save image");
      }

      // Strip old and incorrect exif data
      const exif = this.exif;
      delete exif.Orientation;
      delete exif.Rotation;
      delete exif.ImageHeight;
      delete exif.ImageWidth;
      delete exif.ImageSize;
      delete exif.ModifyDate;
      delete exif.ExifImageHeight;
      delete exif.ExifImageWidth;
      delete exif.ExifImageSize;

      // Update exif data
      await axios.patch(
        generateUrl("/apps/memories/api/image/set-exif/{id}", {
          id: fileid,
        }),
        {
          raw: exif,
        }
      );

      showSuccess(this.t("memories", "Image saved successfully"));
      if (fileid !== this.fileid) {
        emit("files:file:created", { fileid });
      } else {
        emit("files:file:updated", { fileid });
      }
      this.onClose(undefined, false);
    } catch (error) {
      showError(this.t("memories", "Error saving image"));
    }
  }

  /**
   * Show warning if unsaved changes
   */
  onExitWithoutSaving() {
    (<any>OC.dialogs).confirmDestructive(
      translations.changesLoseConfirmation +
        "\n\n" +
        translations.changesLoseConfirmationHint,
      this.t("memories", "Unsaved changes"),
      {
        type: (<any>OC.dialogs).YES_NO_BUTTONS,
        confirm: this.t("memories", "Drop changes"),
        confirmClasses: "error",
        cancel: translations.cancel,
      },
      (decision) => {
        if (!decision) {
          return;
        }
        this.onClose("warning-ignored", false);
      }
    );
  }

  // Key Handlers, override default Viewer arrow and escape key
  handleKeydown(event) {
    event.stopImmediatePropagation();
    // escape key
    if (event.key === "Escape") {
      // Since we cannot call the closeMethod and know if there
      // are unsaved changes, let's fake a close button trigger.
      event.preventDefault();
      (
        document.querySelector(".FIE_topbar-close-button") as HTMLElement
      ).click();
    }

    // ctrl + S = save
    if (event.ctrlKey && event.key === "s") {
      event.preventDefault();
      (
        document.querySelector(".FIE_topbar-save-button") as HTMLElement
      ).click();
    }

    // ctrl + Z = undo
    if (event.ctrlKey && event.key === "z") {
      event.preventDefault();
      (
        document.querySelector(".FIE_topbar-undo-button") as HTMLElement
      ).click();
    }
  }

  /**
   * Watch out for Modal inject in document root
   * That way we can adjust the focusTrap
   *
   * @param {Event} event Dom insertion event
   */
  handleSfxModal(event) {
    if (
      event.target?.classList &&
      event.target.classList.contains("SfxModal-Wrapper")
    ) {
      emit("viewer:trapElements:changed", event.target);
    }
  }
}
</script>

<style lang="scss" scoped>
// Take full screen size ()
.viewer__image-editor {
  position: absolute;
  z-index: 10100;
  top: 0;
  left: 0;
  width: 100%;
  height: 100vh;
}
</style>

<style lang="scss">
// Make sure the editor and its modals are above everything
.SfxModal-Wrapper {
  z-index: 10101 !important;
}

.SfxPopper-wrapper {
  z-index: 10102 !important;
}

// Default styling
.viewer__image-editor,
.SfxModal-Wrapper,
.SfxPopper-wrapper {
  * {
    // Fix font size for the entire image editor
    font-size: var(--default-font-size) !important;
  }

  label,
  button {
    color: var(--color-main-text);
    > span {
      font-size: var(--default-font-size) !important;
    }
  }

  // Fix button ratio and center content
  button {
    display: flex;
    align-items: center;
    justify-content: center;
    min-width: 44px;
    min-height: 44px;
    padding: 6px 12px;
  }
}

// Input styling
.SfxInput-root {
  height: auto !important;
  padding: 0 !important;
  .SfxInput-Base {
    margin: 0 !important;
  }
}

// Select styling
.SfxSelect-root {
  padding: 8px !important;
}

// Global buttons
.SfxButton-root {
  min-height: 44px !important;
  margin: 0 !important;
  border: transparent !important;
  &[color="error"] {
    color: white !important;
    background-color: var(--color-error) !important;
    &:hover,
    &:focus {
      border-color: white !important;
      background-color: var(--color-error-hover) !important;
    }
  }
  &[color="primary"] {
    color: var(--color-primary-text) !important;
    background-color: var(--color-primary-element) !important;
    &:hover,
    &:focus {
      background-color: var(--color-primary-element-hover) !important;
    }
  }
}

// Menu items
.SfxMenuItem-root {
  height: 44px;
  padding-left: 8px !important;
  // Center the menu entry icon and fix width
  > div {
    margin-right: 0;
    padding: 14px;
    // Minus the parent padding-left
    padding: calc(14px - 8px);
    cursor: pointer;
  }

  // Disable jpeg saving (jpg is already here)
  &[value="jpeg"] {
    display: none;
  }
}

// Modal
.SfxModal-Container {
  min-height: 300px;
  padding: 22px;

  // Fill height
  .SfxModal-root,
  .SfxModalTitle-root {
    flex: 1 1 100%;
    justify-content: center;
    color: var(--color-main-text);
  }
  .SfxModalTitle-Icon {
    margin-bottom: 22px !important;
    background: none !important;
    // Fit EmptyContent styling
    svg {
      width: 64px;
      height: 64px;
      opacity: 0.4;
      // Override all coloured icons

      --color-primary: var(--color-main-text);
      --color-error: var(--color-main-text);
    }
  }
  // Hide close icon (use cancel button)
  .SfxModalTitle-Close {
    display: none !important;
  }
  // Modal actions buttons display
  .SfxModalActions-root {
    justify-content: space-evenly !important;
  }
}

// Header buttons
.FIE_topbar-center-options > button,
.FIE_topbar-center-options > label {
  margin-left: 6px !important;
}

// Tabs
.FIE_tabs {
  padding: 6px !important;
  overflow: hidden;
  overflow-y: auto;
}

.FIE_tab {
  width: 80px !important;
  height: 80px !important;
  padding: 8px;
  border-radius: var(--border-radius-large) !important;
  svg {
    width: 16px;
    height: 16px;
  }
  &-label {
    margin-top: 8px !important;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 100%;
    white-space: nowrap;
    display: block !important;
  }

  &:hover,
  &:focus {
    background-color: var(--color-background-hover) !important;
  }

  &[aria-selected="true"] {
    color: var(--color-main-text);
    background-color: var(--color-background-dark);
    box-shadow: 0 0 0 2px var(--color-primary-element);
  }
}

// Tools bar
.FIE_tools-bar {
  &-wrapper {
    max-height: max-content !important;
  }

  // Matching buttons tools
  & > div[class$="-tool-button"],
  & > div[class$="-tool"] {
    display: flex;
    align-items: center;
    justify-content: center;
    min-width: 44px;
    height: 44px;
    padding: 6px 16px;
    border-radius: var(--border-radius-pill);
  }
}

// Crop preset select button
.FIE_crop-presets-opener-button {
  // override default button width
  min-width: 0 !important;
  padding: 5px !important;
  padding-left: 10px !important;
  border: none !important;
  background-color: transparent !important;
}

// Force icon-only style
.FIE_topbar-history-buttons button,
.FIE_topbar-close-button,
.FIE_resize-ratio-locker {
  border: none !important;
  background-color: transparent !important;

  &:hover,
  &:focus {
    background-color: var(--color-background-hover) !important;
  }

  svg {
    width: 16px;
    height: 16px;
  }
}

// Left top bar buttons
.FIE_topbar-history-buttons button {
  &.FIE_topbar-reset-button {
    &::before {
      content: attr(title);
      font-weight: normal;
    }
    svg {
      display: none;
    }
  }
}

// Save button fixes
.FIE_topbar-save-button {
  color: var(--color-primary-text) !important;
  border: none !important;
  background-color: var(--color-primary-element) !important;
  &:hover,
  &:focus {
    background-color: var(--color-primary-element-hover) !important;
  }
}

// Save Modal fixes
.FIE_resize-tool-options {
  .FIE_resize-width-option,
  .FIE_resize-height-option {
    flex: 1 1;
    min-width: 0;
  }
}

// Resize lock
.FIE_resize-ratio-locker {
  margin-right: 8px !important;
  // Icon is very thin
  svg {
    width: 20px;
    height: 20px;
    path {
      stroke-width: 1;
      stroke: var(--color-main-text);
      fill: var(--color-main-text);
    }
  }
}

// Close editor button fixes
.FIE_topbar-close-button {
  svg path {
    // The path viewbox is weird and
    // not correct, this fixes it
    transform: scale(1.6);
  }
}

// Canvas container
.FIE_canvas-container {
  background-color: var(--color-main-background) !important;
}

// Loader
.FIE_spinner::after,
.FIE_spinner-label {
  display: none !important;
}

.FIE_spinner-wrapper {
  background-color: transparent !important;
}

.FIE_spinner::before {
  position: absolute;
  z-index: 2;
  top: 50%;
  left: 50%;
  width: 28px;
  height: 28px;
  margin: -16px 0 0 -16px;
  content: "";
  -webkit-transform-origin: center;
  -ms-transform-origin: center;
  transform-origin: center;
  -webkit-animation: rotate 0.8s infinite linear;
  animation: rotate 0.8s infinite linear;
  border: 2px solid var(--color-loading-light);
  border-top-color: var(--color-loading-dark);
  border-radius: 100%;

  filter: var(--background-invert-if-dark);
}

.FIE_carousel-prev-button,
.FIE_carousel-next-button {
  background: none !important;
}
</style>