import { generateUrl } from '@nextcloud/router'
import { IDay, IPhoto, ITag } from '../../types';
import { constants, hashCode } from '../Utils';
import axios from '@nextcloud/axios'

/**
 * Get list of tags and convert to Days response
 */
export async function getTagsData(): Promise<IDay[]> {
    // Query for photos
    let data: {
        id: number;
        count: number;
        name: string;
        previews: IPhoto[];
    }[] = [];
    try {
        const res = await axios.get<typeof data>(generateUrl('/apps/memories/api/tags'));
        data = res.data;
    } catch (e) {
        throw e;
    }

    // Add flag to previews
    data.forEach(t => t.previews?.forEach((preview) => preview.flag = 0));

    // Convert to days response
    return [{
        dayid: constants.TagDayID.TAGS,
        count: data.length,
        detail: data.map((tag) => ({
            ...tag,
            fileid: hashCode(tag.name),
            flag: constants.c.FLAG_IS_TAG,
            istag: true,
        } as ITag)),
    }]
}