<?php

namespace App\Filament\Resources\AlbumResource\Pages;

use App\Filament\Resources\AlbumResource;
use App\Services\ImageService;
use App\Services\MetaDataService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Log;

class CreateAlbum extends CreateRecord
{
    protected static string $resource = AlbumResource::class;

    public function afterCreate(): void
    {
    $disk = config('filesystems.default');
    Log::info('Album creation started', ['album_id' => $this->record->id, 'image_count' => count($this->record->images ?? []), 'disk' => $disk]);

        // First ensure images are properly processed and encrypted on encrypted disks
        Log::info('Starting image processing', ['album_id' => $this->record->id, 'disk' => $disk]);
        ImageService::ensureImagesProcessed($this->record);
        Log::info('Images processed', ['album_id' => $this->record->id, 'disk' => $disk]);

        // Then extract metadata and update metadata field
        Log::info('Starting metadata extraction', ['album_id' => $this->record->id, 'disk' => $disk]);
        MetaDataService::extractAndSaveMetadata($this->record);
        Log::info('Album creation completed', ['album_id' => $this->record->id, 'disk' => $disk]);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
