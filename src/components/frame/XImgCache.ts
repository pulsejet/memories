import { API } from "../../services/API";
import { workerImporter } from "../../worker";

// Global web worker to fetch images
const worker = new Worker(new URL("./XImgWorker.ts", import.meta.url));

// Import worker functions
const importer = workerImporter(worker);
const fetchImageSrc = importer<string>("fetchImageSrc");
const configMp = importer<void>("configMp");
export const sticky = importer<void>("sticky");

// Is worker configured?
let configured = false;

/** Configure the worker */
function configureWorker() {
  if (configured) return;
  configured = true;
  configMp(API.IMAGE_MULTIPREVIEW());
}

/** Fetch an image with multipreview */
export async function fetchImage(url: string): Promise<string> {
  configureWorker();
  return await fetchImageSrc(url);
}
