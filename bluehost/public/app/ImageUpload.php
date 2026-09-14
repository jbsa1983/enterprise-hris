<?php
// Shared validation for uploaded profile / ID photos. Keeps files small and sane:
// JPG / PNG / WebP only, 2 MB max, with min/max pixel dimensions.
class ImageUpload
{
    const MAX_BYTES = 2097152; // 2 MB
    const MIN_DIM = 80;        // reject tiny/blank images
    const MAX_DIM = 4000;      // reject oversized (decompression) images
    const TYPES = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];

    /** Validate one uploaded image ($_FILES key). Returns [ext, mime, width, height, size, tmp]. */
    public static function validate(string $field): array
    {
        if (empty($_FILES[$field]['tmp_name']) || !is_uploaded_file($_FILES[$field]['tmp_name'])) {
            throw new HttpError('No image was uploaded.', 422);
        }
        $size = (int) ($_FILES[$field]['size'] ?? 0);
        if (($_FILES[$field]['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || $size > self::MAX_BYTES) {
            throw new HttpError('Image is too large. Please use a photo 2 MB or smaller.', 422);
        }
        $info = @getimagesize($_FILES[$field]['tmp_name']);
        if (!$info) throw new HttpError('That file is not a valid image.', 422);
        [$w, $h, $type] = $info;
        if (!isset(self::TYPES[$type])) throw new HttpError('Unsupported image type. Use JPG, PNG or WebP.', 422);
        if ($w < self::MIN_DIM || $h < self::MIN_DIM) {
            throw new HttpError('Image is too small — use at least ' . self::MIN_DIM . '×' . self::MIN_DIM . ' pixels.', 422);
        }
        if ($w > self::MAX_DIM || $h > self::MAX_DIM) {
            throw new HttpError('Image dimensions are too large — keep it under ' . self::MAX_DIM . '×' . self::MAX_DIM . ' pixels.', 422);
        }
        return ['ext' => self::TYPES[$type], 'mime' => image_type_to_mime_type($type),
            'width' => $w, 'height' => $h, 'size' => $size, 'tmp' => $_FILES[$field]['tmp_name']];
    }

    /** MIME for a stored photo extension. */
    public static function mimeFor(string $ext): string
    {
        $ext = strtolower($ext);
        return $ext === 'png' ? 'image/png' : ($ext === 'webp' ? 'image/webp' : 'image/jpeg');
    }

    public static function baseDir(): string
    {
        return dirname(dirname(__DIR__)) . '/storage/photos';
    }
}
