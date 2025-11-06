<?php

namespace App\Observers;

use App\Models\Album;
use Illuminate\Support\Facades\Storage;

class AlbumObserver
{
    /**
     * Handle the Album "created" event.
     */
    public function created(Album $album): void
    {
        //
    }

    /**
     * Handle the Album "updated" event.
     */
    public function updated(Album $album): void
    {
        //
    }

    /**
     * Handle the Album "deleting" event.
     */
    public function deleting(Album $album): void
    {
        $disk = config('filesystems.default');

        if ($disk === 's3') {
            // Delete image files from S3 (not whole directory structure)
            $uploadFolder = config('filesystems.disks.s3.upload_folder', 'sd_develop');
            $albumsPath = "{$uploadFolder}/albums";

            $images = $album->images ?? [];
            foreach ($images as $image) {
                $fileName = basename($image);
                $imagePath = "{$albumsPath}/{$fileName}";
                if (Storage::disk('s3')->exists($imagePath)) {
                    Storage::disk('s3')->delete($imagePath);
                }

                // Also delete thumbnail
                $thumbnailPath = "{$albumsPath}/thumbnails/{$fileName}";
                if (Storage::disk('s3')->exists($thumbnailPath)) {
                    Storage::disk('s3')->delete($thumbnailPath);
                }
            }
        } else {
            // Delete image files from local disk
            $albumsPath = "albums";

            $images = $album->images ?? [];
            foreach ($images as $image) {
                $fileName = basename($image);
                $imagePath = "{$albumsPath}/{$fileName}";
                if (Storage::disk($disk)->exists($imagePath)) {
                    Storage::disk($disk)->delete($imagePath);
                }

                // Also delete thumbnail
                $thumbnailPath = "{$albumsPath}/thumbnails/{$fileName}";
                if (Storage::disk($disk)->exists($thumbnailPath)) {
                    Storage::disk($disk)->delete($thumbnailPath);
                }
            }
        }
    }

    /**
     * Handle the Album "deleted" event.
     */
    public function deleted(Album $album): void {}

    /**
     * Handle the Album "restored" event.
     */
    public function restored(Album $album): void
    {
        //
    }

    /**
     * Handle the Album "force deleted" event.
     */
    public function forceDeleted(Album $album): void
    {
        //
    }
}
