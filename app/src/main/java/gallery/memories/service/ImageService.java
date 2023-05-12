package gallery.memories.service;

import android.content.ContentUris;
import android.content.Context;
import android.graphics.Bitmap;
import android.provider.MediaStore;

import java.io.ByteArrayOutputStream;

public class ImageService {
    Context mCtx;

    public ImageService(Context context) {
        mCtx = context;
    }

    public byte[] getPreview(final long id) throws Exception {
        Bitmap bitmap = MediaStore.Images.Thumbnails.getThumbnail(
            mCtx.getContentResolver(), id, MediaStore.Images.Thumbnails.FULL_SCREEN_KIND, null);

        if (bitmap == null) {
            bitmap = MediaStore.Video.Thumbnails.getThumbnail(
                mCtx.getContentResolver(), id, MediaStore.Video.Thumbnails.FULL_SCREEN_KIND, null);
        }

        if (bitmap == null) {
            throw new Exception("Thumbnail not found");
        }

        ByteArrayOutputStream stream = new ByteArrayOutputStream();
        bitmap.compress(Bitmap.CompressFormat.JPEG, 90, stream);
        return stream.toByteArray();
    }

    public byte[] getFull(final long id) throws Exception {
        Bitmap bitmap = MediaStore.Images.Media.getBitmap(
                mCtx.getContentResolver(), ContentUris.withAppendedId(
                        MediaStore.Images.Media.EXTERNAL_CONTENT_URI, id));

        if (bitmap == null) {
            throw new Exception("Image not found");
        }

        ByteArrayOutputStream stream = new ByteArrayOutputStream();
        bitmap.compress(Bitmap.CompressFormat.JPEG, 90, stream);
        return stream.toByteArray();
    }
}
