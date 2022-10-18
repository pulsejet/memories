import { IFileInfo, IPhoto } from "../types";
import { showError } from '@nextcloud/dialogs'
import { subscribe } from '@nextcloud/event-bus';
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import * as dav from "./DavRequests";

// Key to store sidebar state
const SIDEBAR_KEY = 'memories:sidebar-open';

export class ViewerManager {
    /** Map from fileid to Photo */
    private photoMap = new Map<number, IPhoto>();

    constructor(
        ondelete: (photos: IPhoto[]) => void,
        private updateLoading: (delta: number) => void,
    ) {
        subscribe('files:file:deleted', ({ fileid }: { fileid: number }) => {
            const photo = this.photoMap.get(fileid);
            ondelete([photo]);
        });
    }

    public async open(photo: IPhoto, list?: IPhoto[]) {
        list = list || photo.d?.detail;
        if (!list) return;

        // Repopulate map
        this.photoMap.clear();
        for (const p of list) {
            this.photoMap.set(p.fileid, p);
        }

        // Get file infos
        let fileInfos: IFileInfo[];
        const ids = list.map(p => p.fileid);
        try {
            this.updateLoading(1);
            fileInfos = await dav.getFiles(ids);
        } catch (e) {
            console.error('Failed to load fileInfos', e);
            showError('Failed to load fileInfos');
            return;
        } finally {
            this.updateLoading(-1);
        }
        if (fileInfos.length === 0) {
            return;
        }

        // Fix sorting of the fileInfos
        const itemPositions = {};
        for (const [index, id] of ids.entries()) {
            itemPositions[id] = index;
        }
        fileInfos.sort(function (a, b) {
            return itemPositions[a.fileid] - itemPositions[b.fileid];
        });

        // Get this photo in the fileInfos
        const fInfo = fileInfos.find(d => Number(d.fileid) === photo.fileid);
        if (!fInfo) {
            showError(t('memories', 'Cannot find this photo anymore!'));
            return;
        }

        // Open Nextcloud viewer
        globalThis.OCA.Viewer.open({
            path: fInfo.filename,   // path
            list: fileInfos,        // file list
            canLoop: false,         // don't loop
            onClose: () => {        // on viewer close
                if (globalThis.OCA.Files.Sidebar.file) {
                    localStorage.setItem(SIDEBAR_KEY, '1');
                } else {
                    localStorage.removeItem(SIDEBAR_KEY);
                }
                globalThis.OCA.Files.Sidebar.close();
            },
        });

        // Restore sidebar state
        if (localStorage.getItem(SIDEBAR_KEY) === '1') {
            globalThis.OCA.Files.Sidebar.open(fInfo.filename);
        }
    }
}