import type { IPhoto, IConfig } from '@typings';
import type PhotoSwipe from 'photoswipe';

type Slide = NonNullable<PhotoSwipe['currSlide']>;
type SlideData = Slide['data'];
type Content = Slide['content'];

// Restrict SlideData to _SlideData keys
type _SlideData = Pick<SlideData, 'msrc' | 'width' | 'height' | 'src' | 'thumbCropped'>;

type PsSlideData = _SlideData & {
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

export type PsContent = Omit<Content, 'data'> & {
  data: PsSlideData;
};

export type PsSlide = Omit<Slide, 'data' | 'content'> & {
  data: PsSlideData;
  content: PsContent;
};

export type PsEvent = {
  content: PsContent;
  preventDefault: () => void;
};
