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
            // Delete from S3
            $uploadFolder = config('filesystems.disks.s3.upload_folder', 'sd_develop');
            $directory = "{$uploadFolder}/albums/{$album->id}";
            Storage::disk('s3')->deleteDirectory($directory);
        } else {
            // Delete from local/public disk
            $directory = "albums/{$album->id}";
            Storage::disk($disk)->deleteDirectory($directory);
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
