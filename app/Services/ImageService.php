<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\Album;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Intervention\Image\Laravel\Facades\Image as InterventionImage;
use Illuminate\Support\Facades\Crypt;

class ImageService
{
    // Determine disk based on FILESYSTEM_DISK configuration
    protected static function getDisk(): string
    {
        return config('filesystems.default');
    }

    // Public helper: whether a given disk should have its image contents encrypted
    public static function isEncryptedDisk(string $disk): bool
    {
        return in_array($disk, (array) config('image_encrypt.encrypted_disks', ['s3']));
    }

    // Get upload folder path based on disk
    protected static function getUploadFolder(): string
    {
        if (config('filesystems.default') === 's3') {
            return config('filesystems.disks.s3.upload_folder', 'sd_develop');
        }
        return '';
    }

    // Normalize image path by removing upload folder prefix if present
    protected static function normalizePath(string $path, string $disk): string
    {
        if ($disk === 's3') {
            $uploadFolder = self::getUploadFolder();
            // Remove the upload folder prefix if it exists
            if (strpos($path, $uploadFolder . '/') === 0) {
                return substr($path, strlen($uploadFolder) + 1);
            }
        }
        return $path;
    }

    //Is used to ensure all images in an album are properly processed and stored
    //Images are uploaded directly to the albums folder and processed
    //The images are stored in the database as a JSON column encrypted
    public static function ensureImagesProcessed(Album $album, bool $skipEncryption = false)
    {
        Log::info('ensureImagesProcessed started', ['album_id' => $album->id, 'skip_encryption' => $skipEncryption]);

        $disk = self::getDisk();
        $uploadFolder = self::getUploadFolder();
        $images = $album->images;

        Log::info('Processing images', ['album_id' => $album->id, 'disk' => $disk, 'image_count' => count($images)]);

        $albumsDirectory = $disk === 's3' ? "{$uploadFolder}/albums" : "albums";
        $thumbnailDirectory = "{$albumsDirectory}/thumbnails";

        Log::debug('Directory paths', ['album_id' => $album->id, 'disk' => $disk, 'albums_directory' => $albumsDirectory, 'thumbnail_directory' => $thumbnailDirectory]);

        // Process each image
        foreach ($images as &$image) {
            $fileName = basename($image);
            $imagePath = "{$albumsDirectory}/{$fileName}";
            $thumbnailPath = "{$thumbnailDirectory}/{$fileName}";

            Log::debug('Processing image', ['album_id' => $album->id, 'disk' => $disk, 'file_name' => $fileName, 'image_path' => $imagePath]);

            // Check if image exists in albums folder
            if (Storage::disk($disk)->exists($imagePath)) {
                Log::info('Image already in albums folder', ['album_id' => $album->id, 'disk' => $disk, 'file_name' => $fileName, 'path' => $imagePath]);

                // Image already in albums folder. Ensure it's encrypted on encrypted disks if needed, then check thumbnail.
                if (self::isEncryptedDisk($disk)) {
                    try {
                        $existing = Storage::disk($disk)->get($imagePath);
                        // If decrypting succeeds it's already encrypted; if it throws, assume plaintext and encrypt in-place
                        try {
                            Crypt::decryptString($existing);
                            Log::debug('Image already encrypted', ['album_id' => $album->id, 'disk' => $disk, 'file_name' => $fileName]);
                            // already encrypted
                        } catch (\Exception $e) {
                            // plaintext object found in albums folder — encrypt it in place
                            Log::warning('Plaintext image found in albums folder, encrypting in place', ['album_id' => $album->id, 'disk' => $disk, 'file_name' => $fileName]);
                            try {
                                $encrypted = Crypt::encryptString(base64_encode($existing));
                                Storage::disk($disk)->put($imagePath, $encrypted, ['visibility' => 'public']);
                                Log::info('Image encrypted in place', ['album_id' => $album->id, 'disk' => $disk, 'file_name' => $fileName]);
                            } catch (\Exception $e2) {
                                Log::error('Error encrypting in-place ' . $imagePath . ': ' . $e2->getMessage(), ['album_id' => $album->id, 'disk' => $disk]);
                            }
                        }
                    } catch (\Exception $e) {
                        Log::error('Error reading existing file ' . $imagePath . ': ' . $e->getMessage(), ['album_id' => $album->id, 'disk' => $disk]);
                    }
                }

                // Generate thumbnail (generateThumbnail will attempt to decrypt if needed)
                Log::info('Generating thumbnail for existing image', ['album_id' => $album->id, 'disk' => $disk, 'file_name' => $fileName]);
                self::generateThumbnail($albumsDirectory, $imagePath);
            } else {
                Log::warning('Image not found in albums folder', ['album_id' => $album->id, 'disk' => $disk, 'file_name' => $fileName, 'image_path' => $imagePath]);
            }

            // Update the image path - normalize by removing upload folder prefix
            $image = self::normalizePath($imagePath, $disk);
            Log::debug('Image path updated', ['album_id' => $album->id, 'disk' => $disk, 'new_image_path' => $image]);
        }

        // Save the updated paths back to the JSON column
        $album->images = $images;
        $album->save();
        Log::info('ensureImagesProcessed completed', ['album_id' => $album->id, 'disk' => $disk]);
    }

    public static function generateThumbnail($mainNewDirectory, $mainImageUrl, bool $force = false)
    {
        Log::info('generateThumbnail started', ['image_url' => $mainImageUrl, 'force' => $force]);

        $disk = self::getDisk();
        $thumbnailDirectory = "{$mainNewDirectory}/thumbnails";
        $thumbnailFileName = basename($mainImageUrl);
        $thumbnailPath = "{$thumbnailDirectory}/{$thumbnailFileName}";

        Log::debug('Thumbnail paths', ['disk' => $disk, 'thumbnail_directory' => $thumbnailDirectory, 'thumbnail_path' => $thumbnailPath]);

        // Check if thumbnail already exists
        if (Storage::disk($disk)->exists($thumbnailPath) && ! $force) {
            Log::info('Thumbnail already exists, skipping generation', ['disk' => $disk, 'thumbnail_path' => $thumbnailPath]);
            return;
        }

        try {
            Log::info('Reading image content', ['disk' => $disk, 'image_url' => $mainImageUrl]);
            // Get the original image content.
            $imageContent = Storage::disk($disk)->get($mainImageUrl);

            if (self::isEncryptedDisk($disk)) {
                Log::debug('Disk is encrypted, attempting to decrypt', ['disk' => $disk, 'image_url' => $mainImageUrl]);
                // Try to decrypt; if decryption fails, abort thumbnail generation to avoid
                // passing ciphertext to Intervention which will log decoding errors.
                try {
                    $decoded = Crypt::decryptString($imageContent);
                    $imageContent = base64_decode($decoded);
                    Log::info('Image decrypted successfully', ['disk' => $disk, 'image_url' => $mainImageUrl]);
                } catch (\Exception $e) {
                    Log::warning('Skipping thumbnail generation for ' . $mainImageUrl . ': decryption failed.', ['disk' => $disk]);
                    return;
                }
            }

            // Create thumbnail using Intervention Image
            Log::info('Creating thumbnail with Intervention Image', ['disk' => $disk, 'image_url' => $mainImageUrl]);
            $image = InterventionImage::read($imageContent);
            $image->cover(200, 200);

            // Save thumbnail. If thumbnail encryption is enabled in config, encrypt it too.
            if (config('image_encrypt.encrypt_thumbnails', false) && self::isEncryptedDisk($disk)) {
                Log::info('Encrypting thumbnail', ['disk' => $disk, 'thumbnail_path' => $thumbnailPath]);
                $thumbContents = $image->toJpeg();
                $encryptedThumb = Crypt::encryptString(base64_encode($thumbContents));
                Storage::disk($disk)->put($thumbnailPath, $encryptedThumb, ['visibility' => 'public']);
                Log::info('Encrypted thumbnail saved', ['disk' => $disk, 'thumbnail_path' => $thumbnailPath]);
            } else {
                Log::info('Saving thumbnail (no encryption)', ['disk' => $disk, 'thumbnail_path' => $thumbnailPath]);
                Storage::disk($disk)->put(
                    $thumbnailPath,
                    $image->toJpeg(),
                    ['visibility' => 'public']
                );
                Log::info('Thumbnail saved', ['disk' => $disk, 'thumbnail_path' => $thumbnailPath]);
            }

            Log::info('generateThumbnail completed', ['disk' => $disk, 'thumbnail_path' => $thumbnailPath]);
        } catch (\Exception $e) {
            Log::error('Error generating thumbnail for ' . $mainImageUrl . ': ' . $e->getMessage(), ['disk' => $disk]);
        }
    }

    //Used for deleting images that are not in the record's images (the filament component delete the url from the json array, but not from the storage)
    //This is used when updating an album
    public static function deleteAllImagesWhoAreNotInJsonFromStorage(Album $album)
    {
        Log::info('deleteAllImagesWhoAreNotInJsonFromStorage started', ['album_id' => $album->id]);

        $disk = self::getDisk();
        $uploadFolder = self::getUploadFolder();
        $images = array_map(function ($image) {
            return basename($image);
        }, $album->images);

        Log::debug('Processing images', ['album_id' => $album->id, 'disk' => $disk, 'image_count' => count($images)]);

        if ($disk === 's3') {
            $folderPath = "{$uploadFolder}/albums/{$album->id}";
            $thumbnailFolderPath = "{$folderPath}/thumbnails";
        } else {
            $folderPath = "albums/{$album->id}";
            $thumbnailFolderPath = "{$folderPath}/thumbnails";
        }

        Log::debug('Folder paths', ['album_id' => $album->id, 'disk' => $disk, 'folder_path' => $folderPath, 'thumbnail_folder_path' => $thumbnailFolderPath]);

        // Get all images in the folder
        $allImages = array_diff(Storage::disk($disk)->files($folderPath), ['.', '..']);
        $allThumbnails = array_diff(Storage::disk($disk)->files($thumbnailFolderPath), ['.', '..']);

        Log::debug('Storage contents', ['album_id' => $album->id, 'disk' => $disk, 'all_images_count' => count($allImages), 'all_thumbnails_count' => count($allThumbnails)]);

        // Find images that are not in the record's images
        $imagesToRemove = array_diff($allImages, array_map(function ($image) use ($folderPath) {
            return $folderPath . '/' . $image;
        }, $images));

        // Find thumbnails that are not in the record's images
        $thumbnailsToRemove = array_diff($allThumbnails, array_map(function ($image) use ($thumbnailFolderPath) {
            return $thumbnailFolderPath . '/' . $image;
        }, $images));

        Log::info('Files to remove', ['album_id' => $album->id, 'disk' => $disk, 'images_to_remove_count' => count($imagesToRemove), 'thumbnails_to_remove_count' => count($thumbnailsToRemove)]);

        // Remove images that are not in the record's images
        foreach ($imagesToRemove as $image) {
            try {
                Log::info('Deleting image', ['album_id' => $album->id, 'disk' => $disk, 'image_path' => $image]);
                Storage::disk($disk)->delete($image);
            } catch (\Exception $e) {
                Log::error('Error deleting image ' . $image . ': ' . $e->getMessage(), ['album_id' => $album->id, 'disk' => $disk]);
            }
        }

        foreach ($thumbnailsToRemove as $thumbnail) {
            try {
                Log::info('Deleting thumbnail', ['album_id' => $album->id, 'disk' => $disk, 'thumbnail_path' => $thumbnail]);
                Storage::disk($disk)->delete($thumbnail);
            } catch (\Exception $e) {
                Log::error('Error deleting thumbnail ' . $thumbnail . ': ' . $e->getMessage(), ['album_id' => $album->id, 'disk' => $disk]);
            }
        }

        Log::info('deleteAllImagesWhoAreNotInJsonFromStorage completed', ['album_id' => $album->id, 'disk' => $disk]);
    }


}
