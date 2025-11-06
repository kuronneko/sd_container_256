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
        $uploadFolder = $disk === 's3' ? config('filesystems.disks.s3.upload_folder', 'sd_develop') : '';
        $albumFolder = $uploadFolder ? "{$uploadFolder}/albums/{$album->id}" : "albums/{$album->id}";

        // Delete entire album folder with all images and thumbnails
        if (Storage::disk($disk)->exists($albumFolder)) {
            Storage::disk($disk)->deleteDirectory($albumFolder);
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
