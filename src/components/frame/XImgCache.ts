import { API } from "../../services/API";
import { workerImporter } from "../../worker";
import type * as w from "./XImgWorker";

// Global web worker to fetch images
const worker = new Worker(new URL("./XImgWorker.ts", import.meta.url));

// Import worker functions
const importer = workerImporter(worker);
export const fetchImage = importer<typeof w.fetchImageSrc>("fetchImageSrc");
export const sticky = importer<typeof w.sticky>("sticky");

// Configure worker on startup
document.addEventListener("DOMContentLoaded", () =>
  importer<typeof w.configure>("configure")({
    multiUrl: API.IMAGE_MULTIPREVIEW(),
  })
);
