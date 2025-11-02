<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\Album;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Intervention\Image\Laravel\Facades\Image as InterventionImage;

class ImageService
{
    // Determine disk based on FILESYSTEM_DISK configuration
    protected static function getDisk(): string
    {
        return config('filesystems.default');
    }

    // Get upload folder path based on disk
    protected static function getUploadFolder(): string
    {
        if (config('filesystems.default') === 's3') {
            return env('AWS_UPLOAD_FOLDER', 'sd_develop');
        }
        return '';
    }

    //Is used because i want to save all images in a folder with the album ID
    //In creating a new album, the images are saved in the temp folder
    //When the album is saved, the images are moved from the temp folder to the album folder
    //The images are also saved in the database as a JSON column
    public static function moveImagesFromTempFolderToIdAlbumFolder(Album $album)
    {
        $disk = self::getDisk();
        $uploadFolder = self::getUploadFolder();
        $images = $album->images;

        if ($disk === 's3') {
            $newDirectory = "{$uploadFolder}/albums/{$album->id}";
            $tempDirectory = "{$uploadFolder}/albums/temp";
        } else {
            $newDirectory = "albums/{$album->id}";
            $tempDirectory = "albums/temp";
        }

        // Process each image
        foreach ($images as &$image) {
            $fileName = basename($image);
            $tempPath = "{$tempDirectory}/{$fileName}";
            $newPath = "{$newDirectory}/{$fileName}";

            // Move image from temp to album folder
            if (Storage::disk($disk)->exists($tempPath)) {
                Storage::disk($disk)->move($tempPath, $newPath);
            }

            // Update the image path
            $image = $newPath;

            // Generate thumbnail
            self::generateThumbnail($newDirectory, $newPath);
        }

        // Save the updated paths back to the JSON column
        $album->images = $images;
        $album->save();
    }

    public static function generateThumbnail($mainNewDirectory, $mainImageUrl)
    {
        $disk = self::getDisk();
        $thumbnailDirectory = "{$mainNewDirectory}/thumbnails";
        $thumbnailFileName = basename($mainImageUrl);
        $thumbnailPath = "{$thumbnailDirectory}/{$thumbnailFileName}";

        // Check if thumbnail already exists
        if (Storage::disk($disk)->exists($thumbnailPath)) {
            return;
        }

        try {
            // Get the original image content
            $imageContent = Storage::disk($disk)->get($mainImageUrl);

            // Create thumbnail using Intervention Image
            $image = InterventionImage::read($imageContent);
            $image->cover(200, 200);

            // Save thumbnail
            if ($disk === 's3') {
                Storage::disk($disk)->put(
                    $thumbnailPath,
                    $image->toJpeg(),
                    ['visibility' => 'public']
                );
            } else {
                Storage::disk($disk)->put(
                    $thumbnailPath,
                    $image->toJpeg(),
                    ['visibility' => 'public']
                );
            }
        } catch (\Exception $e) {
            Log::error('Error generating thumbnail for ' . $mainImageUrl . ': ' . $e->getMessage());
        }
    }

    //Used for deleting images that are not in the record's images (the filament component delete the url from the json array, but not from the storage)
    //This is used when updating an album
    public static function deleteAllImagesWhoAreNotInJsonFromStorage(Album $album)
    {
        $disk = self::getDisk();
        $uploadFolder = self::getUploadFolder();
        $images = array_map(function ($image) {
            return basename($image);
        }, $album->images);

        if ($disk === 's3') {
            $folderPath = "{$uploadFolder}/albums/{$album->id}";
            $thumbnailFolderPath = "{$folderPath}/thumbnails";
        } else {
            $folderPath = "albums/{$album->id}";
            $thumbnailFolderPath = "{$folderPath}/thumbnails";
        }

        // Get all images in the folder
        $allImages = array_diff(Storage::disk($disk)->files($folderPath), ['.', '..']);
        $allThumbnails = array_diff(Storage::disk($disk)->files($thumbnailFolderPath), ['.', '..']);

        // Find images that are not in the record's images
        $imagesToRemove = array_diff($allImages, array_map(function ($image) use ($folderPath) {
            return $folderPath . '/' . $image;
        }, $images));

        // Find thumbnails that are not in the record's images
        $thumbnailsToRemove = array_diff($allThumbnails, array_map(function ($image) use ($thumbnailFolderPath) {
            return $thumbnailFolderPath . '/' . $image;
        }, $images));

        // Remove images that are not in the record's images
        foreach ($imagesToRemove as $image) {
            Storage::disk($disk)->delete($image);
        }

        foreach ($thumbnailsToRemove as $thumbnail) {
            Storage::disk($disk)->delete($thumbnail);
        }
    }
}
