import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';

export default {
  async list() {
    const url = generateUrl('/apps/memories/api/trip-videos');
    const response = await axios.get(url);
    return response.data;
  },

  getVideoUrl(tripId: number) {
    return generateUrl(`/apps/memories/api/trip-videos/${tripId}`);
  },
};
