import videojs from "video.js";
globalThis.vidjs = videojs;

import "videojs-contrib-quality-levels";
import "video.js/dist/video-js.min.css";

import Plyr from "plyr";
(<any>globalThis).Plyr = Plyr;
import "plyr/dist/plyr.css";

import plyrsvg from "../assets/plyr.svg";
(<any>Plyr).defaults.iconUrl = plyrsvg;
(<any>Plyr).defaults.blankVideo = "";
