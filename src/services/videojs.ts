import videojs from 'video.js';
_m.video.videojs = videojs;

import 'video.js/dist/video-js.min.css';

import Plyr from 'plyr';
_m.video.Plyr = Plyr;
import 'plyr/dist/plyr.css';

import plyrsvg from '@assets/plyr.svg';
(<any>Plyr).defaults.iconUrl = plyrsvg;
(<any>Plyr).defaults.blankVideo = '';
