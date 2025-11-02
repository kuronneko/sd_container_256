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
     * Handle the Gimnasio "deleting" event.
     */
    public function deleting(Album $album): void
    {
        // Define the directory based on the album ID
        $directory = "albums/{$album->id}";

        // Delete the entire directory from the 'public' disk
        Storage::disk('public')->deleteDirectory($directory);
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
