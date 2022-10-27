<!--
 - @copyright Copyright (c) 2022 Louis Chemineau <louis@chmn.me>
 -
 - @author Louis Chemineau <louis@chmn.me>
 -
 - @license AGPL-3.0-or-later
 -
 - This program is free software: you can redistribute it and/or modify
 - it under the terms of the GNU Affero General Public License as
 - published by the Free Software Foundation, either version 3 of the
 - License, or (at your option) any later version.
 -
 - This program is distributed in the hope that it will be useful,
 - but WITHOUT ANY WARRANTY; without even the implied warranty of
 - MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 - GNU Affero General Public License for more details.
 -
 - You should have received a copy of the GNU Affero General Public License
 - along with this program. If not, see <http://www.gnu.org/licenses/>.
 -
 -->
<template>
    <form v-if="!showCollaboratorView" class="album-form" @submit.prevent="submit">
        <div class="form-inputs">
            <NcTextField ref="nameInput"
                :value.sync="albumName"
                type="text"
                name="name"
                :required="true"
                autofocus="true"
                :placeholder="t('photos', 'Name of the album')" />
            <label>
                <NcTextField :value.sync="albumLocation"
                    name="location"
                    type="text"
                    :placeholder="t('photos', 'Location of the album')" />
            </label>
        </div>
        <div class="form-buttons">
            <span class="left-buttons">
                <NcButton v-if="displayBackButton"
                    :aria-label="t('photos', 'Go back to the previous view.')"
                    type="tertiary"
                    @click="back">
                    {{ t('photos', 'Back') }}
                </NcButton>
            </span>
            <span class="right-buttons">
                <NcButton v-if="sharingEnabled && !editMode"
                    :aria-label="t('photos', 'Go to the add collaborators view.')"
                    type="secondary"
                    :disabled="albumName.trim() === '' || loading"
                    @click="showCollaboratorView = true">
                    <template #icon>
                        <AccountMultiplePlus />
                    </template>
                    {{ t('photos', 'Add collaborators') }}
                </NcButton>
                <NcButton :aria-label="editMode ? t('photos', 'Save.') : t('photos', 'Create the album.')"
                    type="primary"
                    :disabled="albumName === '' || loading"
                    @click="submit()">
                    <template #icon>
                        <NcLoadingIcon v-if="loading" />
                        <Send v-else />
                    </template>
                    {{ editMode ? t('photos', 'Save') : t('photos', 'Create album') }}
                </NcButton>
            </span>
        </div>
    </form>
    <!-- <CollaboratorsSelectionForm v-else
        :album-name="albumName"
        :allow-public-link="false">
        <template slot-scope="{collaborators}">
            <span class="left-buttons">
                <NcButton :aria-label="t('photos', 'Back to the new album form.')"
                    type="tertiary"
                    @click="showCollaboratorView = false">
                    {{ t('photos', 'Back') }}
                </NcButton>
            </span>
            <span class="right-buttons">
                <NcButton :aria-label="editMode ? t('photos', 'Save.') : t('photos', 'Create the album.')"
                    type="primary"
                    :disabled="albumName.trim() === '' || loading"
                    @click="submit(collaborators)">
                    <template #icon>
                        <NcLoadingIcon v-if="loading" />
                        <Send v-else />
                    </template>
                    {{ editMode ? t('photos', 'Save') : t('photos', 'Create album') }}
                </NcButton>
            </span>
        </template>
    </CollaboratorsSelectionForm> -->
</template>

<script lang="ts">
import { Component, Emit, Mixins, Prop } from 'vue-property-decorator';
import GlobalMixin from '../../mixins/GlobalMixin';

import { getCurrentUser } from '@nextcloud/auth'
import { NcButton, NcLoadingIcon, NcTextField } from '@nextcloud/vue'
import { IAlbum } from '../../types';
import moment from 'moment';
import * as dav from '../../services/DavRequests';

// import CollaboratorsSelectionForm from './CollaboratorsSelectionForm.vue'

import Send from 'vue-material-design-icons/Send.vue'
import AccountMultiplePlus from 'vue-material-design-icons/AccountMultiplePlus.vue'


@Component({
    components: {
		NcButton,
		NcLoadingIcon,
		NcTextField,
		// CollaboratorsSelectionForm,

        Send,
        AccountMultiplePlus,
    },
})
export default class AlbumForm extends Mixins(GlobalMixin) {
    @Prop() private album: any;
    @Prop() private displayBackButton: boolean;

    private showCollaboratorView = false;
    private albumName = '';
    private albumLocation = '';
    private loading = false;

    /**
     * @return Whether sharing is enabled.
     */
    get editMode(): boolean {
        return Boolean(this.album);
    }

    /**
     * @return Whether sharing is enabled.
     */
    get sharingEnabled(): boolean {
        return window.OC.Share !== undefined
    }

	mounted() {
		if (this.editMode) {
			this.albumName = this.album.basename
			this.albumLocation = this.album.location
		}
		this.$nextTick(() => {
			(<any>this.$refs.nameInput).$el.getElementsByTagName('input')[0].focus()
		})
	}

    submit(collaborators = []) {
        if (this.albumName === '' || this.loading) {
            return
        }
        if (this.editMode) {
            this.handleUpdateAlbum()
        } else {
            this.handleCreateAlbum(collaborators)
        }
    }

    async handleCreateAlbum(collaborators = []) {
        try {
            this.loading = true
            let album = {
                    basename: this.albumName,
                    filename: `/photos/${getCurrentUser().uid}/albums/${this.albumName}`,
                    nbItems: 0,
                    location: this.albumLocation,
                    lastPhoto: -1,
                    date: moment().format('MMMM YYYY'),
                    collaborators,
            }
            await dav.createAlbum(album.basename);

            if (this.albumLocation !== '' || collaborators.length !== 0) {
                album = await dav.updateAlbum(album, {
                    albumName: this.albumName,
                    properties: {
                        location: this.albumLocation,
                        collaborators,
                    },
                });
            }

            this.$emit('done', { album })
        } finally {
            this.loading = false
        }
    }

    async handleUpdateAlbum() {
        try {
            this.loading = true
            let album = { ...this.album }
            if (this.album.basename !== this.albumName) {
                album = await dav.renameAlbum(this.album, { currentAlbumName: this.album.basename, newAlbumName: this.albumName })
            }
            if (this.album.location !== this.albumLocation) {
                album.location = await dav.updateAlbum(this.album, { albumName: this.albumName, properties: { location: this.albumLocation } })
            }
            this.$emit('done', { album })
        } finally {
            this.loading = false
        }
    }

    @Emit('back')
    back() {}
}
</script>
<style lang="scss" scoped>
.album-form {
	display: flex;
	flex-direction: column;
	height: 350px;
	padding: 16px;
	.form-title {
		font-weight: bold;
	}
	.form-subtitle {
		color: var(--color-text-lighter);
	}
	.form-inputs {
		flex-grow: 1;
		justify-items: flex-end;
		input {
			width: 100%;
		}
		label {
			display: flex;
			margin-top: 16px;
			:deep svg {
				margin-right: 12px;
			}
		}
	}
	.form-buttons {
		display: flex;
		justify-content: space-between;
		.left-buttons, .right-buttons {
			display: flex;
		}
		.right-buttons {
			justify-content: flex-end;
		}
		button {
			margin-right: 16px;
		}
	}
}
.left-buttons {
	flex-grow: 1;
}
</style>