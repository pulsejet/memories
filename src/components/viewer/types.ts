import type { IPhoto, IConfig } from '@typings';
import type PhotoSwipe from 'photoswipe';

type Slide = NonNullable<PhotoSwipe['currSlide']>;
type SlideData = Slide['data'];
type Content = Slide['content'];

// Restrict SlideData to _SlideData keys
type PsData = Pick<SlideData, 'msrc' | 'width' | 'height' | 'thumbCropped'> & {
  /** The type of content. */
  type: 'image' | 'video';
  /** The original source of the image.*/
  src: string;
  /** The original photo object. */
  photo: IPhoto;
  /** The source of the high resolution image. */
  highSrc: string[];
  /** The condition for loading the high resolution image. */
  highSrcCond: IConfig['high_res_cond'];
};

export type PsContent = Omit<Content, 'data'> & {
  data: PsData;
};

export type PsSlide = Omit<Slide, 'data' | 'content'> & {
  data: PsData & {
    /** Initial highSrcCond value for restore (nativex). */
    highSrcCondRestore?: IConfig['high_res_cond'];
    /** Native image data state. */
    nxImageState?: 'loading' | 'loaded' | 'error';
    /** Native hole video state. */
    nxVideoState?: 'loading' | 'loaded' | 'error';
    /** Native image should be disabled */
    nxImageDisabled?: boolean;
  };
  content: PsContent;
};

export type PsEvent = {
  content: PsContent;
  preventDefault: () => void;
};
