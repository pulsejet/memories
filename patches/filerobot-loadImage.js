/**
 * Filerobot has issues with loading images from HTML elements.
 * We need to wait for the react object to be created properly before loading.
 * This is a monkey patch to select an existing image using a global, but after wait.
 */

import loadImageOriginal from './loadImageOriginal.js';

var loadImage = function() {
    const image = globalThis._fileRobotOverrideImage;
    if (image) {
        return new Promise((resolve, reject) => {
            setTimeout(() => {
                resolve(image);
            }, 0);
        });
    }

    return loadImageOriginal.apply(this, arguments);
}

export default loadImage;