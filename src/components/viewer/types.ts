import Content from 'photoswipe/dist/types/slide/content';
import Slide, { type _SlideData } from 'photoswipe/dist/types/slide/slide';
import type { IPhoto, IConfig } from '@typings';

type PsAugment = {
  data: _SlideData & {
    /** The original source of the image.*/
    src: string;
    /** The original photo object. */
    photo: IPhoto;
    /** The source of the high resolution image. */
    highSrc: string[];
    /** The condition for loading the high resolution image. */
    highSrcCond: IConfig['high_res_cond'];
    /** The type of content. */
    type: 'image' | 'video';
  };
};
export type PsSlide = Slide &
  PsAugment & {
    content: PsContent;
  };
export type PsContent = Content & PsAugment;
export type PsEvent = {
  content: PsContent;
  preventDefault: () => void;
};
