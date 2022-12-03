/**
 * @copyright Copyright (c) 2019 John Molakvoæ <skjnldsv@protonmail.com>
 *
 * @author John Molakvoæ <skjnldsv@protonmail.com>
 *
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */
import camelcase from "camelcase";
import { IFileInfo, IPhoto } from "../types";
import { API } from "./API";
import { isNumber } from "./NumberUtils";

/**
 * Get an url encoded path
 *
 * @param {string} path the full path
 * @return {string} url encoded file path
 */
const encodeFilePath = function (path) {
  const pathSections = (path.startsWith("/") ? path : `/${path}`).split("/");
  let relativePath = "";
  pathSections.forEach((section) => {
    if (section !== "") {
      relativePath += "/" + encodeURIComponent(section);
    }
  });
  return relativePath;
};

/**
 * Extract dir and name from file path
 *
 * @param {string} path the full path
 * @return {string[]} [dirPath, fileName]
 */
const extractFilePaths = function (path) {
  const pathSections = path.split("/");
  const fileName = pathSections[pathSections.length - 1];
  const dirPath = pathSections.slice(0, pathSections.length - 1).join("/");
  return [dirPath, fileName];
};

/**
 * Sorting comparison function
 *
 * @param {object} fileInfo1 file 1 fileinfo
 * @param {object} fileInfo2 file 2 fileinfo
 * @param {string} key key to sort with
 * @param {boolean} [asc=true] sort ascending?
 * @return {number}
 */
const sortCompare = function (fileInfo1, fileInfo2, key, asc = true) {
  // favorite always first
  if (fileInfo1.isFavorite && !fileInfo2.isFavorite) {
    return -1;
  } else if (!fileInfo1.isFavorite && fileInfo2.isFavorite) {
    return 1;
  }

  // if this is a number, let's sort by integer
  if (isNumber(fileInfo1[key]) && isNumber(fileInfo2[key])) {
    return asc
      ? Number(fileInfo2[key]) - Number(fileInfo1[key])
      : Number(fileInfo1[key]) - Number(fileInfo2[key]);
  }

  // else we sort by string, so let's sort directories first
  if (fileInfo1.type !== "file" && fileInfo2.type === "file") {
    return asc ? -1 : 1;
  } else if (fileInfo1.type === "file" && fileInfo2.type !== "file") {
    return asc ? 1 : -1;
  }

  // if this is a date, let's sort by date
  if (
    isNumber(new Date(fileInfo1[key]).getTime()) &&
    isNumber(new Date(fileInfo2[key]).getTime())
  ) {
    return asc
      ? new Date(fileInfo2[key]).getTime() - new Date(fileInfo1[key]).getTime()
      : new Date(fileInfo1[key]).getTime() - new Date(fileInfo2[key]).getTime();
  }

  // finally sort by name
  return asc
    ? fileInfo1[key]
        ?.toString()
        ?.localeCompare(
          fileInfo2[key].toString(),
          globalThis.OC.getLanguage()
        ) || 1
    : -fileInfo1[key]
        ?.toString()
        ?.localeCompare(
          fileInfo2[key].toString(),
          globalThis.OC.getLanguage()
        ) || -1;
};

const genFileInfo = function (obj) {
  const fileInfo = {};

  Object.keys(obj).forEach((key) => {
    const data = obj[key];

    // flatten object if any
    if (!!data && typeof data === "object") {
      Object.assign(fileInfo, genFileInfo(data));
    } else {
      // format key and add it to the fileInfo
      if (data === "false") {
        fileInfo[camelcase(key)] = false;
      } else if (data === "true") {
        fileInfo[camelcase(key)] = true;
      } else {
        fileInfo[camelcase(key)] = isNumber(data) ? Number(data) : data;
      }
    }
  });
  return fileInfo;
};

/** Get preview URL from photo object */
const getPreviewUrl = function (
  photo: IPhoto | IFileInfo,
  square: boolean,
  size: number | [number, number]
) {
  const [x, y] = typeof size === "number" ? [size, size] : size;

  // Build query
  const query = new URLSearchParams();
  query.set("c", photo.etag);
  query.set("x", x.toString());
  query.set("y", y.toString());
  query.set("a", square ? "0" : "1");

  return API.Q(API.IMAGE_PREVIEW(photo.fileid), query);
};

export {
  encodeFilePath,
  extractFilePaths,
  sortCompare,
  genFileInfo,
  getPreviewUrl,
};
