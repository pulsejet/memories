package gallery.memories.service;

import android.content.ContentUris;
import android.content.Context;
import android.graphics.Bitmap;
import android.provider.MediaStore;

import java.io.ByteArrayOutputStream;
import java.io.IOException;

public class ImageService {
    Context mCtx;

    public ImageService(Context context) {
        mCtx = context;
    }

    public byte[] getFromURI(String uri) throws Exception {
        // URI looks like nativex://<type>/<id>
        String[] parts = uri.split("/");
        if (parts.length != 4) {
            throw new Exception("Invalid URI path");
        }

        final String type = parts[2];
        final long id = Long.parseLong(parts[3]);

        Bitmap bitmap = null;

        if (type.equals("preview")) {
            bitmap = MediaStore.Images.Thumbnails.getThumbnail(
                mCtx.getContentResolver(), id, MediaStore.Images.Thumbnails.MINI_KIND, null);
        } else if (type.equals("full")) {
            try {
                bitmap = MediaStore.Images.Media.getBitmap(
                        mCtx.getContentResolver(), ContentUris.withAppendedId(
                        MediaStore.Images.Media.EXTERNAL_CONTENT_URI, id));
            } catch (IOException e) {
                e.printStackTrace();
            }
        } else {
            throw new Exception("Invalid request type");
        }

        if (bitmap == null) {
            throw new Exception("Thumbnail not found");
        }

        ByteArrayOutputStream stream = new ByteArrayOutputStream();
        bitmap.compress(Bitmap.CompressFormat.JPEG, 90, stream);
        return stream.toByteArray();
    }
}
