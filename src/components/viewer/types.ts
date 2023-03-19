import Content from "photoswipe/dist/types/slide/content";
import Slide, { _SlideData } from "photoswipe/dist/types/slide/slide";
import { IPhoto } from "../../types";

type PsAugment = {
  data: _SlideData & {
    photo?: IPhoto;
  };
};
export type PsSlide = Slide & PsAugment;
export type PsContent = Content & PsAugment;
export type PsEvent = {
  content: PsContent;
  preventDefault: () => void;
};
