<?php

namespace App\Filament\Resources\AlbumResource\Pages;

use Exception;
use App\Models\Album;
use Filament\Actions;
use App\Models\Imagen;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Filament\Resources\AlbumResource;
use App\Services\ImageService;
use Filament\Resources\Pages\CreateRecord;
use Intervention\Image\Laravel\Facades\Image as InterventionImage;

class CreateAlbum extends CreateRecord
{
    protected static string $resource = AlbumResource::class;

    public function afterCreate(): void
    {
        ImageService::moveImagesFromTempFolderToIdAlbumFolder($this->record);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
