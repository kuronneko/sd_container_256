<?php

namespace App\Filament\Resources\AlbumResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use App\Filament\Resources\AlbumResource;
use App\Services\ImageService;
use App\Services\MetaDataService;
use Illuminate\Support\Facades\Log;

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
        $disk = config('filesystems.default');
        Log::info('Album update started', ['album_id' => $this->record->id, 'image_count' => count($this->record->images ?? []), 'disk' => $disk]);

        // Update metadata from current images
        Log::info('Updating metadata from images', ['album_id' => $this->record->id, 'disk' => $disk]);
        MetaDataService::updateMetadataFromImages($this->record, true);
        Log::info('Album update completed', ['album_id' => $this->record->id, 'disk' => $disk]);

        // Process and encrypt all images on encrypted disks and generate thumbnails
        Log::info('Starting image processing', ['album_id' => $this->record->id, 'disk' => $disk]);
        ImageService::ensureImagesProcessed($this->record);
        Log::info('Images processed', ['album_id' => $this->record->id, 'disk' => $disk]);

        // Delete images that were removed
        Log::info('Deleting removed images from storage', ['album_id' => $this->record->id, 'disk' => $disk]);
        ImageService::deleteAllImagesWhoAreNotInJsonFromStorage($this->record);
        Log::info('Removed images deleted', ['album_id' => $this->record->id, 'disk' => $disk]);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
