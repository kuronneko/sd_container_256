<?php

namespace App\Filament\Resources\AlbumResource\Pages;

use App\Models\Album;
use Filament\Actions;
use App\Models\Imagen;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Filament\Resources\AlbumResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAlbum extends CreateRecord
{
    protected static string $resource = AlbumResource::class;

    public function afterCreate(): void
    {
        // Ensure $this->record->images is a string before decoding
        $images = $this->record->images;

        // Define the new directory based on the album ID
        $newDirectory = "albums/{$this->record->id}";

        // Create the new directory if it doesn't exist
        if (!Storage::disk('public')->exists($newDirectory)) {
            Storage::disk('public')->makeDirectory($newDirectory, 0755, true);
        }

        // Process each image
        foreach ($images as &$image) {
            // Define the new path for the image
            $newPath = "{$newDirectory}/" . basename($image);

            if (!Storage::disk('public')->exists('albums/temp/' . $this->record->id)) {
                Storage::disk('public')->move(
                    'albums/temp/' . basename($image),
                    'albums/' . $this->record->id . '/' . basename($image)
                );
            }

            // Update the image path
            $image = $newPath;
        }

        // Save the updated paths back to the JSON column
        $this->record->images = ($images);
        $this->record->save();
    }
}
