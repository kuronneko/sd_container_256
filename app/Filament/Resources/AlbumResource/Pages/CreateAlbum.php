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
        if (!is_dir($newDirectory)) {
            mkdir(public_path('/storage/' . $newDirectory), 0755, true);
        }

        // Process each image
        foreach ($images as &$image) {
            // Define the new path for the image
            $newPath = "{$newDirectory}/" . basename($image);

            if (!file_exists(public_path('/storage/albums/temp/' . $this->record->id))) {
                rename(public_path('/storage/albums/temp/' . basename($image)), public_path('/storage/albums/' . $this->record->id . '/' . basename($image)));
            }

            // Update the image path
            $image = $newPath;
        }

        // Save the updated paths back to the JSON column
        $this->record->images = ($images);
        $this->record->save();
    }
}
