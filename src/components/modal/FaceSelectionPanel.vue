<template>
  <div class="face-selection">
    <!-- One face -->
    <div v-if="face" class="fields">
      <div class="face-title">{{ nameOf(face) }}</div>
      <p class="state">{{ originText(face) }}</p>
      <p v-if="participation" class="state">{{ participation }}</p>
      <p v-for="(hint, i) in hints" :key="i" class="state hint-text">{{ hint }}</p>

      <template v-if="canEditName">
        <NcTextField
          ref="editField"
          class="field"
          v-model="editName"
          :label="t('memories', 'Name')"
          :label-visible="false"
          :placeholder="t('memories', 'Name')"
          list="memories-manual-face-names"
          @keypress.enter="saveName()"
        />
        <p v-if="canReassign && groupSize !== null && !ignored" class="group">
          {{
            n('memories', 'Its group has {count} face.', 'Its group has {count} faces.', groupSize, {
              count: groupSize,
            })
          }}
          <a class="group-link" :href="groupHref" target="_blank" rel="noopener noreferrer">
            {{ t('memories', 'Show the photos of this group') }}
            <OpenInNewIcon :size="16" />
          </a>
        </p>
        <NcCheckboxRadioSwitch v-if="canMoveGroup" v-model="wholeGroup">
          {{ t('memories', 'Move the whole group') }}
        </NcCheckboxRadioSwitch>
        <p v-if="target" class="scope">{{ editScope }}</p>
      </template>
      <p v-else class="hint">
        {{
          t(
            'memories',
            'This face is not in a group yet. It can be named once the face recognition has placed it in one, on its next run.',
          )
        }}
      </p>
    </div>

    <!-- Several faces, picked with Ctrl+click -->
    <div v-else class="fields">
      <div class="face-title">
        {{ n('memories', '{count} face selected', '{count} faces selected', faces.length, { count: faces.length }) }}
      </div>
      <p v-for="(note, i) in notes" :key="i" class="state">{{ note }}</p>
    </div>

    <NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>

    <div class="actions">
      <NcButton @click="$emit('cancel')">
        {{ t('memories', 'Cancel') }}
      </NcButton>
      <NcButton v-if="canDelete" :variant="confirmDelete ? 'error' : 'secondary'" :disabled="saving" @click="remove">
        {{ deleteLabel }}
      </NcButton>
      <NcButton v-if="canUnignore" :disabled="saving" @click="unignore">
        {{ t('memories', 'Stop ignoring') }}
      </NcButton>
      <NcButton v-else-if="canIgnore" :disabled="saving" @click="ignore">
        {{ t('memories', 'Ignore') }}
      </NcButton>
      <NcButton v-if="face && canEditName" variant="primary" :disabled="!canSaveName" @click="saveName">
        {{ t('memories', 'Save') }}
      </NcButton>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent, defineAsyncComponent } from 'vue';
import type { PropType } from 'vue';

import NcButton from '@nextcloud/vue/components/NcButton';
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard';
const NcTextField = defineAsyncComponent(() => import('@nextcloud/vue/components/NcTextField'));
const NcCheckboxRadioSwitch = defineAsyncComponent(() => import('@nextcloud/vue/components/NcCheckboxRadioSwitch'));

import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue';

import { translate as t, translatePlural as n } from '@services/l10n';
import * as utils from '@services/utils';
import {
  faceRecognitionAssignCluster,
  faceRecognitionDeleteFaces,
  faceRecognitionIgnoreFaces,
  faceRecognitionNameFace,
  faceRecognitionUnignoreFaces,
  type IFaceLimits,
  type IFaceRectForFile,
} from '@services/dav/face';

import {
  canDelete,
  errorText,
  focusWithoutScrolling,
  hintsOf,
  inputOf,
  isIgnored,
  markingScope,
  nameOf,
  originText,
  participationText,
  reassignScope,
} from './faceMarking';

/**
 * The faces selected on the photo: what one of them is, and naming it; and
 * for one or several, deleting or ignoring them. What is done here is told
 * with 'done', and the dialog then reads the faces of the photo again.
 */
export default defineComponent({
  name: 'FaceSelectionPanel',
  components: { NcButton, NcNoteCard, NcTextField, NcCheckboxRadioSwitch, OpenInNewIcon },

  props: {
    /** The faces selected, at least one */
    faces: {
      type: Array as PropType<IFaceRectForFile[]>,
      required: true,
    },
    limits: {
      type: Object as PropType<IFaceLimits | null>,
      default: null,
    },
    /** Face Recognition on the server does not report the state of the faces */
    legacy: {
      type: Boolean,
      default: false,
    },
    knownNames: {
      type: Array as PropType<string[]>,
      default: () => [],
    },
  },

  emits: {
    /** Something was changed on the server; the text says what, for the user */
    done: (_message: string) => true,
    cancel: () => true,
  },

  data: () => ({
    editName: '',
    wholeGroup: false,
    /** Delete was clicked once, and waits for the second click that confirms it */
    confirmDelete: false,
    saving: false,
    error: '',
  }),

  computed: {
    /** The one face selected, or null when there are several. */
    face(): IFaceRectForFile | null {
      return this.faces.length === 1 ? this.faces[0] : null;
    },

    /** Which faces are selected, so that a change of the selection starts afresh */
    selection(): string {
      return this.faces.map((face) => face.id).join(',');
    },

    ignored(): boolean {
      return !!this.face && isIgnored(this.face);
    },

    participation(): string {
      return this.face ? participationText(this.face, this.limits) : '';
    },

    hints(): string[] {
      return this.face ? hintsOf(this.face) : [];
    },

    /** A face can only be moved to a person through its group. */
    canReassign(): boolean {
      return this.face?.cluster !== null && this.face?.cluster !== undefined;
    },

    /**
     * A face in no group, like a marking saved without a name, is named on
     * its own and gets a group for that person. Face Recognition before this
     * change has no way to do that.
     */
    canName(): boolean {
      return !!this.face && !this.canReassign && !this.legacy;
    },

    canEditName(): boolean {
      return this.canReassign || this.canName;
    },

    target(): string {
      return this.editName.trim();
    },

    groupSize(): number | null {
      return this.face?.clusterSize ?? null;
    },

    /** Moving the whole group is offered when there is more in it than this face. */
    canMoveGroup(): boolean {
      return !this.legacy && this.groupSize !== null && this.groupSize > 1 && !this.ignored;
    },

    groupHref(): string {
      const cluster = this.face?.cluster;
      if (cluster === null || cluster === undefined) return '';
      return this.$router.resolve({ name: 'facerecognition', params: { user: utils.uid ?? '', name: String(cluster) } })
        .href;
    },

    /** What saving the name changes, for a face in a group or in none */
    editScope(): string {
      return this.canName
        ? markingScope(this.target, this.knownNames)
        : reassignScope(this.target, this.wholeGroup && this.canMoveGroup, this.groupSize);
    },

    canSaveName(): boolean {
      return this.canEditName && !!this.target && !this.saving;
    },

    /** Only what was put there by hand can be deleted, and all of the selection or nothing. */
    canDelete(): boolean {
      return !this.legacy && this.faces.every(canDelete);
    },

    canIgnore(): boolean {
      return !this.legacy && this.faces.some((face) => !isIgnored(face));
    },

    canUnignore(): boolean {
      return !this.legacy && this.faces.every(isIgnored);
    },

    deleteLabel(): string {
      const count = this.faces.length;
      return this.confirmDelete
        ? n('memories', 'Really delete {count} face', 'Really delete {count} faces', count, { count })
        : t('memories', 'Delete');
    },

    /** What is worth knowing about the faces selected together. */
    notes(): string[] {
      const notes: string[] = [];
      if (!this.legacy && !this.faces.every(canDelete)) {
        notes.push(
          t(
            'memories',
            'Faces found by the automatic analysis cannot be deleted, since its next run on the photo would find them again. They can be ignored.',
          ),
        );
      }
      if (this.canIgnore) {
        notes.push(
          t(
            'memories',
            'Ignored faces stay on the photo, faintly, but they are nobody: they do not show among the people, and the automatic recognition leaves them alone.',
          ),
        );
      }
      return notes;
    },
  },

  watch: {
    selection: {
      immediate: true,
      handler() {
        // A single face can be named, and the field starts with its name.
        this.editName = this.face?.personName ?? '';
        // Only this face, unless the user says otherwise.
        this.wholeGroup = false;
        this.confirmDelete = false;
        this.error = '';
      },
    },
  },

  methods: {
    nameOf,
    originText,

    /** Puts the cursor in the name field, when there is one, without scrolling to it. */
    focusName() {
      this.$nextTick(() => focusWithoutScrolling(() => inputOf(this.$refs.editField)));
    },

    async saveName(): Promise<void> {
      const face = this.face;
      if (!this.canSaveName || !face) return;
      const target = this.target;
      const wholeGroup = this.wholeGroup && this.canMoveGroup;
      await this.run(
        async () => {
          if (face.cluster === null) {
            await faceRecognitionNameFace(face.id, target);
            return t('memories', 'Person "{name}" tagged.', { name: target });
          }
          await faceRecognitionAssignCluster(face.cluster, target, wholeGroup ? undefined : face.id);
          return wholeGroup
            ? t('memories', 'Group assigned to "{name}".', { name: target })
            : t('memories', 'Face reassigned to "{name}".', { name: target });
        },
        t('memories', 'Failed to reassign the face.'),
      );
    },

    /** Deletes the faces, on the second click: the first one asks. */
    async remove(): Promise<void> {
      if (!this.canDelete) return;
      if (!this.confirmDelete) {
        this.confirmDelete = true;
        return;
      }
      await this.run(
        async () => {
          const { faceIds } = await faceRecognitionDeleteFaces(this.ids());
          return n('memories', '{count} face deleted.', '{count} faces deleted.', faceIds.length, {
            count: faceIds.length,
          });
        },
        t('memories', 'The faces could not be deleted.'),
      );
    },

    async ignore(): Promise<void> {
      if (!this.canIgnore) return;
      await this.run(
        async () => {
          const { faceIds } = await faceRecognitionIgnoreFaces(this.ids());
          return n('memories', '{count} face ignored.', '{count} faces ignored.', faceIds.length, {
            count: faceIds.length,
          });
        },
        t('memories', 'The faces could not be ignored.'),
      );
    },

    async unignore(): Promise<void> {
      if (!this.canUnignore) return;
      await this.run(
        async () => {
          const { faceIds } = await faceRecognitionUnignoreFaces(this.ids());
          return n(
            'memories',
            '{count} face is not ignored any more. The face recognition places it on its next run.',
            '{count} faces are not ignored any more. The face recognition places them on its next run.',
            faceIds.length,
            { count: faceIds.length },
          );
        },
        t('memories', 'The faces could not be changed.'),
      );
    },

    ids(): number[] {
      return this.faces.map((face) => face.id);
    },

    /**
     * Runs a change on the server, and tells the dialog what came of it. On
     * a failure the selection stays, to try again.
     */
    async run(change: () => Promise<string>, failed: string): Promise<void> {
      if (this.saving) return;
      this.saving = true;
      this.error = '';
      try {
        this.$emit('done', await change());
      } catch (e) {
        console.error(e);
        this.error = errorText(e, failed);
      } finally {
        this.saving = false;
        this.confirmDelete = false;
      }
    },
  },
});
</script>

<style lang="scss" scoped>
.face-selection {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-top: 6px;
}

.fields {
  display: flex;
  flex-direction: column;
  gap: 8px;

  .face-title {
    font-weight: bold;
  }

  .state,
  .group,
  .hint {
    margin: 0;
    font-size: 0.9em;
  }

  .hint {
    opacity: 0.8;
  }

  // A link has to look like one: the text around it has the same color.
  .group-link {
    display: inline-flex;
    align-items: center;
    gap: 2px;
    margin-left: 4px;
    color: var(--color-primary-element);
    font-weight: bold;
    text-decoration: underline;

    &:hover,
    &:focus-visible {
      text-decoration-thickness: 2px;
    }
  }

  .hint-text {
    color: var(--color-warning-text, var(--color-warning));
  }

  .scope {
    margin: 0;
    font-size: 0.9em;
    padding: 6px 8px;
    border-left: 3px solid var(--color-primary-element);
    background: var(--color-background-hover);
  }
}

// The actions of the selection, next to it, as the dialog has its own below.
.actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 8px;
}
</style>
