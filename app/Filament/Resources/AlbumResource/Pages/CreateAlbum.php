<?php

namespace App\Filament\Resources\AlbumResource\Pages;

use App\Filament\Resources\AlbumResource;
use App\Services\ImageService;
use App\Services\MetaDataService;
use Filament\Resources\Pages\CreateRecord;

class CreateAlbum extends CreateRecord
{
    protected static string $resource = AlbumResource::class;

    public function afterCreate(): void
    {
        // First move images from temp folder
        ImageService::moveImagesFromTempFolderToIdAlbumFolder($this->record);

        // Then extract metadata and update metadata field
        MetaDataService::extractAndSaveMetadata($this->record);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
