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

<script setup lang="ts">
import { computed, defineAsyncComponent, nextTick, ref, useTemplateRef, watch } from 'vue';
import { useRouter } from 'vue-router';

import NcButton from '@nextcloud/vue/components/NcButton';
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard';
import NcTextField from '@nextcloud/vue/components/NcTextField';
const NcCheckboxRadioSwitch = defineAsyncComponent(() => import('@nextcloud/vue/components/NcCheckboxRadioSwitch'));

import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue';

import { t, n } from '@services/l10n';
import * as utils from '@services/utils/common';
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
  canDelete as isDeletable,
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
defineOptions({
  name: 'FaceSelectionPanel',
});

const props = withDefaults(
  defineProps<{
    /** The faces selected, at least one */
    faces: IFaceRectForFile[];
    limits?: IFaceLimits | null;
    /** Face Recognition on the server does not report the state of the faces */
    legacy?: boolean;
    knownNames?: string[];
  }>(),
  {
    limits: null,
    legacy: false,
    knownNames: () => [],
  },
);

const emit = defineEmits<{
  /** Something was changed on the server; the text says what, for the user */
  done: [message: string];
  cancel: [];
}>();

const router = useRouter();
const editField = useTemplateRef('editField');

const editName = ref('');
const wholeGroup = ref(false);
/** Delete was clicked once, and waits for the second click that confirms it */
const confirmDelete = ref(false);
const saving = ref(false);
const error = ref('');

/** The one face selected, or null when there are several. */
const face = computed(() => (props.faces.length === 1 ? props.faces[0] : null));

/** Which faces are selected, so that a change of the selection starts afresh */
const selection = computed(() => props.faces.map((f) => f.id).join(','));

const ignored = computed(() => !!face.value && isIgnored(face.value));

const participation = computed(() => (face.value ? participationText(face.value, props.limits) : ''));

const hints = computed(() => (face.value ? hintsOf(face.value) : []));

/** A face can only be moved to a person through its group. */
const canReassign = computed(() => face.value?.cluster !== null && face.value?.cluster !== undefined);

/**
 * A face in no group, like a marking saved without a name, is named on
 * its own and gets a group for that person. Face Recognition before this
 * change has no way to do that.
 */
const canName = computed(() => !!face.value && !canReassign.value && !props.legacy);

const canEditName = computed(() => canReassign.value || canName.value);

const target = computed(() => editName.value.trim());

const groupSize = computed(() => face.value?.clusterSize ?? null);

/** Moving the whole group is offered when there is more in it than this face. */
const canMoveGroup = computed(() => !props.legacy && groupSize.value !== null && groupSize.value > 1 && !ignored.value);

const groupHref = computed(() => {
  const cluster = face.value?.cluster;
  if (cluster === null || cluster === undefined) return '';
  return router.resolve({ name: 'facerecognition', params: { user: utils.uid ?? '', name: String(cluster) } }).href;
});

/** What saving the name changes, for a face in a group or in none */
const editScope = computed(() =>
  canName.value
    ? markingScope(target.value, props.knownNames)
    : reassignScope(target.value, wholeGroup.value && canMoveGroup.value, groupSize.value),
);

const canSaveName = computed(() => canEditName.value && !!target.value && !saving.value);

/** Only what was put there by hand can be deleted, and all of the selection or nothing. */
const canDelete = computed(() => !props.legacy && props.faces.every(isDeletable));

const canIgnore = computed(() => !props.legacy && props.faces.some((f) => !isIgnored(f)));

const canUnignore = computed(() => !props.legacy && props.faces.every(isIgnored));

const deleteLabel = computed(() => {
  const count = props.faces.length;
  return confirmDelete.value
    ? n('memories', 'Really delete {count} face', 'Really delete {count} faces', count, { count })
    : t('memories', 'Delete');
});

/** What is worth knowing about the faces selected together. */
const notes = computed(() => {
  const list: string[] = [];
  if (!props.legacy && !props.faces.every(isDeletable)) {
    list.push(
      t(
        'memories',
        'Faces found by the automatic analysis cannot be deleted, since its next run on the photo would find them again. They can be ignored.',
      ),
    );
  }
  if (canIgnore.value) {
    list.push(
      t(
        'memories',
        'Ignored faces stay on the photo, faintly, but they are nobody: they do not show among the people, and the automatic recognition leaves them alone.',
      ),
    );
  }
  return list;
});

watch(
  selection,
  () => {
    // A single face can be named, and the field starts with its name.
    editName.value = face.value?.personName ?? '';
    // Only this face, unless the user says otherwise.
    wholeGroup.value = false;
    confirmDelete.value = false;
    error.value = '';
  },
  { immediate: true },
);

/** Puts the cursor in the name field, when there is one, without scrolling to it. */
function focusName() {
  nextTick(() => focusWithoutScrolling(() => inputOf(editField.value)));
}

async function saveName(): Promise<void> {
  const selected = face.value;
  if (!canSaveName.value || !selected) return;
  const name = target.value;
  const moveGroup = wholeGroup.value && canMoveGroup.value;
  await run(
    async () => {
      if (selected.cluster === null) {
        await faceRecognitionNameFace(selected.id, name);
        return t('memories', 'Person "{name}" tagged.', { name });
      }
      await faceRecognitionAssignCluster(selected.cluster, name, moveGroup ? undefined : selected.id);
      return moveGroup
        ? t('memories', 'Group assigned to "{name}".', { name })
        : t('memories', 'Face reassigned to "{name}".', { name });
    },
    t('memories', 'Failed to reassign the face.'),
  );
}

/** Deletes the faces, on the second click: the first one asks. */
async function remove(): Promise<void> {
  if (!canDelete.value) return;
  if (!confirmDelete.value) {
    confirmDelete.value = true;
    return;
  }
  await run(
    async () => {
      const { faceIds } = await faceRecognitionDeleteFaces(ids());
      return n('memories', '{count} face deleted.', '{count} faces deleted.', faceIds.length, {
        count: faceIds.length,
      });
    },
    t('memories', 'The faces could not be deleted.'),
  );
}

async function ignore(): Promise<void> {
  if (!canIgnore.value) return;
  await run(
    async () => {
      const { faceIds } = await faceRecognitionIgnoreFaces(ids());
      return n('memories', '{count} face ignored.', '{count} faces ignored.', faceIds.length, {
        count: faceIds.length,
      });
    },
    t('memories', 'The faces could not be ignored.'),
  );
}

async function unignore(): Promise<void> {
  if (!canUnignore.value) return;
  await run(
    async () => {
      const { faceIds } = await faceRecognitionUnignoreFaces(ids());
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
}

function ids(): number[] {
  return props.faces.map((f) => f.id);
}

/**
 * Runs a change on the server, and tells the dialog what came of it. On
 * a failure the selection stays, to try again.
 */
async function run(change: () => Promise<string>, failed: string): Promise<void> {
  if (saving.value) return;
  saving.value = true;
  error.value = '';
  try {
    emit('done', await change());
  } catch (e) {
    console.error(e);
    error.value = errorText(e, failed);
  } finally {
    saving.value = false;
    confirmDelete.value = false;
  }
}

defineExpose({ focusName });
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
