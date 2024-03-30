import axios from '@nextcloud/axios';
import nextcloudsvg from '@assets/nextcloud.svg';

/**
 * Get the Nextcloud logo from the current theme
 * @returns SVG string
 */
export async function getNextcloudLogo(): Promise<string> {
  const style = getComputedStyle(document.body);
  const override = style.getPropertyValue('--image-logoheader') || style.getPropertyValue('--image-logo');
  if (override) {
    // Extract URL from CSS url
    const url = override.match(/url\(["']?([^"']*)["']?\)/i)?.[1];
    if (!url) throw new Error('No URL found');

    // Fetch image
    const blob = (await axios.get(url, { responseType: 'blob' })).data;
    console.log('Loaded logo', blob);

    // Convert to data URI and pass to logo
    const reader = new FileReader();
    reader.readAsDataURL(blob);
    return await new Promise<string>((resolve, reject) => {
      reader.onloadend = () => resolve(reader.result as string);
      reader.onerror = reject;
      reader.onabort = reject;
    });
  }

  // Fall back to default logo
  return nextcloudsvg;
}
