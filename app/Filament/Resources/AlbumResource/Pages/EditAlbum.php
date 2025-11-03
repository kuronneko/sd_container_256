<?php

namespace App\Filament\Resources\AlbumResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use App\Filament\Resources\AlbumResource;
use App\Services\ImageService;
use App\Services\MetaDataService;

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
        // Move new images from temp folder to album folder and generate thumbnails
        ImageService::moveImagesFromTempFolderToIdAlbumFolder($this->record);

        // Delete images that were removed
        ImageService::deleteAllImagesWhoAreNotInJsonFromStorage($this->record);

        // Update metadata from current images
        MetaDataService::updateMetadataFromImages($this->record);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
