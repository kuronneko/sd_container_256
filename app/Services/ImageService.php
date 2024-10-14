<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\Album;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Laravel\Facades\Image as InterventionImage;

class ImageService
{
    //Is used because i want to save all images in a folder with the album ID
    //In creating a new album, the images are saved in the temp folder
    //When the album is saved, the images are moved from the temp folder to the album folder
    //The images are also saved in the database as a JSON column
    public static function moveImagesFromTempFolderToIdAlbumFolder(Album $album)
    {
        // Ensure $this->record->images is a string before decoding
        $images = $album->images;

        // Define the new directory based on the album ID
        $newDirectory = "albums/{$album->id}";

        // Create the new directory if it doesn't exist
        if (!Storage::disk('public')->exists($newDirectory)) {
            Storage::disk('public')->makeDirectory($newDirectory, 0755, true);
        }

        // Process each image
        foreach ($images as &$image) {
            // Define the new path for the image
            $newPath = "{$newDirectory}/" . basename($image);

            if (!Storage::disk('public')->exists('albums/temp/' . $album->id)) {
                Storage::disk('public')->move(
                    'albums/temp/' . basename($image),
                    'albums/' . $album->id . '/' . basename($image)
                );
            }
            // Update the image path
            $image = $newPath;

            self::generateThumbnail($newDirectory, $image);
        }

        // Save the updated paths back to the JSON column
        $album->images = ($images);
        $album->save();
    }

    public static function generateThumbnail($mainNewDirectory, $mainImageUrl)
    {
        $thumbnailDirectory = "{$mainNewDirectory}/thumbnails";
        $thumbnailFileName = "{$thumbnailDirectory}/" . basename($mainImageUrl);

        if (!Storage::disk('public')->exists($thumbnailDirectory)) {
            Storage::disk('public')->makeDirectory($thumbnailDirectory, 0755, true);
        }

        if (!Storage::disk('public')->exists($thumbnailFileName)) {
            $imageContent = Storage::disk('public')->get($mainImageUrl);

            $image = InterventionImage::read($imageContent);

            $image->cover(200, 200);

            $image->save(public_path('/storage/' . $thumbnailFileName));
        }
    }

    //Used for deleting images that are not in the record's images (the filament component delete the url from the json array, but not from the storage)
    //This is used when updating an album
    public static function deleteAllImagesWhoAreNotInJsonFromStorage(Album $album)
    {
        $images = array_map(function ($image) {
            return basename($image);
        }, $album->images);

        $folderPath = 'albums/' . $album->id;
        $thumbnailFolderPath = $folderPath . '/thumbnails';

        // Get all images in the folder
        $allImages = array_diff(Storage::disk('public')->files($folderPath), ['.', '..']);
        $allThumbnails = array_diff(Storage::disk('public')->files($thumbnailFolderPath), ['.', '..']);

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
            Storage::disk('public')->delete($image);
        }

        foreach ($thumbnailsToRemove as $thumbnail) {
            Storage::disk('public')->delete($thumbnail);
        }
    }
}
