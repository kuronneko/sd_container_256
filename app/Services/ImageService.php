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
    public static function ensureImagesProcessed(Album $album)
    {
        Log::info('ensureImagesProcessed started', ['album_id' => $album->id]);

        $disk = self::getDisk();
        $uploadFolder = self::getUploadFolder();
        $images = $album->images;

        Log::info('Processing images', ['album_id' => $album->id, 'disk' => $disk, 'image_count' => count($images)]);

        $albumsDirectory = $disk === 's3' ? "{$uploadFolder}/albums" : "albums";
        $albumSpecificDirectory = $disk === 's3' ? "{$uploadFolder}/albums/{$album->id}" : "albums/{$album->id}";
        $thumbnailDirectory = "{$albumSpecificDirectory}/thumbnails";

        Log::debug('Directory paths', ['album_id' => $album->id, 'disk' => $disk, 'albums_directory' => $albumsDirectory, 'album_specific_directory' => $albumSpecificDirectory, 'thumbnail_directory' => $thumbnailDirectory]);

        // Process each image
        foreach ($images as &$image) {
            $fileName = basename($image);
            $baseImagePath = "{$albumsDirectory}/{$fileName}";
            $albumImagePath = "{$albumSpecificDirectory}/{$fileName}";
            $thumbnailPath = "{$thumbnailDirectory}/{$fileName}";

            Log::debug('Processing image', ['album_id' => $album->id, 'disk' => $disk, 'file_name' => $fileName, 'base_path' => $baseImagePath, 'album_path' => $albumImagePath]);

            // Check if image exists in base albums folder or album-specific folder
            $imagePath = null;
            $isInBaseFolder = false;

            if (Storage::disk($disk)->exists($baseImagePath)) {
                $imagePath = $baseImagePath;
                $isInBaseFolder = true;
                Log::info('Image found in base albums folder', ['album_id' => $album->id, 'disk' => $disk, 'file_name' => $fileName]);
            } elseif (Storage::disk($disk)->exists($albumImagePath)) {
                $imagePath = $albumImagePath;
                Log::info('Image already in album-specific folder', ['album_id' => $album->id, 'disk' => $disk, 'file_name' => $fileName]);
            } else {
                Log::warning('Image not found in either location', ['album_id' => $album->id, 'disk' => $disk, 'file_name' => $fileName, 'base_path' => $baseImagePath, 'album_path' => $albumImagePath]);
            }

            if ($imagePath) {
                // If image is in base folder, move it to album-specific folder
                if ($isInBaseFolder) {
                    Log::info('Moving image from base folder to album-specific folder', ['album_id' => $album->id, 'disk' => $disk, 'from' => $baseImagePath, 'to' => $albumImagePath]);
                    try {
                        $content = Storage::disk($disk)->get($baseImagePath);
                        Storage::disk($disk)->put($albumImagePath, $content, ['visibility' => 'public']);
                        Storage::disk($disk)->delete($baseImagePath);
                        Log::info('Image moved successfully', ['album_id' => $album->id, 'disk' => $disk]);
                        $imagePath = $albumImagePath;
                    } catch (\Exception $e) {
                        Log::error('Error moving image from base folder: ' . $e->getMessage(), ['album_id' => $album->id, 'disk' => $disk]);
                    }

                    // Also move thumbnail if it exists in base folder
                    $baseThumbnailPath = $disk === 's3' ? "{$uploadFolder}/albums/thumbnails/{$fileName}" : "albums/thumbnails/{$fileName}";
                    if (Storage::disk($disk)->exists($baseThumbnailPath)) {
                        Log::info('Moving thumbnail from base folder to album-specific folder', ['album_id' => $album->id, 'disk' => $disk, 'from' => $baseThumbnailPath, 'to' => $thumbnailPath]);
                        try {
                            $thumbContent = Storage::disk($disk)->get($baseThumbnailPath);
                            Storage::disk($disk)->put($thumbnailPath, $thumbContent, ['visibility' => 'public']);
                            Storage::disk($disk)->delete($baseThumbnailPath);
                            Log::info('Thumbnail moved successfully', ['album_id' => $album->id, 'disk' => $disk]);
                        } catch (\Exception $e) {
                            Log::error('Error moving thumbnail from base folder: ' . $e->getMessage(), ['album_id' => $album->id, 'disk' => $disk]);
                        }
                    }
                }

            }

            // Update the image path - normalize by removing upload folder prefix and use album-specific path
            $image = self::normalizePath($albumImagePath, $disk);
            Log::debug('Image path updated', ['album_id' => $album->id, 'disk' => $disk, 'new_image_path' => $image]);
        }

        // Save the updated paths back to the JSON column
        $album->images = $images;
        $album->save();

        Log::info('ensureImagesProcessed completed', ['album_id' => $album->id, 'disk' => $disk]);
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
