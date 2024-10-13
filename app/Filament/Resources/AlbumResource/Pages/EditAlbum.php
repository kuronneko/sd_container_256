<?php

namespace App\Filament\Resources\AlbumResource\Pages;

use Filament\Actions;
use Illuminate\Support\Facades\Storage;
use Filament\Resources\Pages\EditRecord;
use App\Filament\Resources\AlbumResource;

class EditAlbum extends EditRecord
{
    protected static string $resource = AlbumResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        $images = array_map(function ($image) {
            return basename($image);
        }, $this->record->images);

        $folderPath = 'albums/' . $this->record->id;

        // Get all images in the folder
        $allImages = array_diff(Storage::disk('public')->files($folderPath), ['.', '..']);

        // Find images that are not in the record's images
        $imagesToRemove = array_diff($allImages, array_map(function ($image) use ($folderPath) {
            return $folderPath . '/' . $image;
        }, $images));

        // Remove images that are not in the record's images
        foreach ($imagesToRemove as $image) {
            Storage::disk('public')->delete($image);
        }
    }
}
