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

    //Is used because i want to save all images in a folder with the album ID
    //In creating a new album, the images are saved in the temp folder
    //When the album is saved, the images are moved from the temp folder to the album folder
    //The images are also saved in the database as a JSON column
    //When editing, new images are in temp folder and need to be moved, old images stay in place
    public static function moveImagesFromTempFolderToIdAlbumFolder(Album $album, bool $skipEncryption = false)
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

            // Check if image is in temp folder (new upload)
            if (Storage::disk($disk)->exists($tempPath)) {
                if ($disk === 's3') {
                    // If requested to skip encryption, just move the object as-is.
                    if ($skipEncryption) {
                        try {
                            Storage::disk($disk)->move($tempPath, $newPath);
                            self::generateThumbnail($newDirectory, $newPath);
                        } catch (\Exception $e) {
                            Log::error('Error moving image to ' . $newPath . ': ' . $e->getMessage());
                        }
                    } else {
                        // Read the uploaded (plain) content, encrypt and store into new path
                        try {
                            $contents = Storage::disk($disk)->get($tempPath);
                            // Encrypt with base64 wrapper to keep binary safe
                            $encrypted = Crypt::encryptString(base64_encode($contents));
                            Storage::disk($disk)->put($newPath, $encrypted, ['visibility' => 'public']);
                            // Delete the temp (plain) object
                            Storage::disk($disk)->delete($tempPath);
                            // Generate thumbnail from decrypted content
                            self::generateThumbnail($newDirectory, $newPath);
                        } catch (\Exception $e) {
                            Log::error('Error encrypting/moving image to ' . $newPath . ': ' . $e->getMessage());
                        }
                    }
                } else {
                    // Local disk: just move
                    Storage::disk($disk)->move($tempPath, $newPath);
                    // Generate thumbnail for newly moved image
                    self::generateThumbnail($newDirectory, $newPath);
                }
            } else if (Storage::disk($disk)->exists($newPath)) {
                // Image already in album folder. Ensure it's encrypted on S3 if needed, then check thumbnail.
                if ($disk === 's3') {
                    try {
                        $existing = Storage::disk($disk)->get($newPath);
                        // If decrypting succeeds it's already encrypted; if it throws, assume plaintext and encrypt in-place
                        try {
                            Crypt::decryptString($existing);
                            // already encrypted
                        } catch (\Exception $e) {
                            // plaintext object found in album folder — encrypt it in place
                            try {
                                $encrypted = Crypt::encryptString(base64_encode($existing));
                                Storage::disk($disk)->put($newPath, $encrypted, ['visibility' => 'public']);
                            } catch (\Exception $e2) {
                                Log::error('Error encrypting in-place ' . $newPath . ': ' . $e2->getMessage());
                            }
                        }
                    } catch (\Exception $e) {
                        Log::error('Error reading existing file ' . $newPath . ': ' . $e->getMessage());
                    }
                }

                // Generate thumbnail (generateThumbnail will attempt to decrypt if needed)
                self::generateThumbnail($newDirectory, $newPath);
            }

            // Update the image path - normalize by removing upload folder prefix
            $image = self::normalizePath($newPath, $disk);
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
            // Get the original image content. If stored encrypted on S3, try to decrypt first.
            $imageContent = Storage::disk($disk)->get($mainImageUrl);

            if ($disk === 's3') {
                try {
                    $decoded = Crypt::decryptString($imageContent);
                    $imageContent = base64_decode($decoded);
                } catch (\Exception $e) {
                    // If decrypt fails, assume the file is plain; continue with original content
                }
            }

            // Create thumbnail using Intervention Image
            $image = InterventionImage::read($imageContent);
            $image->cover(200, 200);

            // Save thumbnail (thumbnails are kept as public, unencrypted files for quick display)
            Storage::disk($disk)->put(
                $thumbnailPath,
                $image->toJpeg(),
                ['visibility' => 'public']
            );
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
