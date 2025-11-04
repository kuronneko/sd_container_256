<?php

namespace App\Filament\Resources\AlbumResource\Widgets;

use Filament\Widgets\Widget;
use Filament\Forms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Concerns\InteractsWithForms;

class FilesystemOverview extends Widget implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'filament.resources.album-resource.widgets.filesystem-overview';

    protected int | string | array $columnSpan = 'full';

    protected function getFormSchema(): array
    {
        return [
            Forms\Components\Section::make('Filesystem Configuration')
                ->description('Current filesystem disk configuration')
                ->schema([
                    Forms\Components\Grid::make()
                        ->schema([
                            Forms\Components\Placeholder::make('disk')
                                ->label('Disk')
                                ->content(config('filesystems.default'))
                                ->columnSpan(1),
                            ...($this->getFilesystemInfoFields()),
                        ])
                        ->columns(4),
                ])
                ->columnSpan('full'),
        ];
    }

    protected function getFilesystemInfoFields(): array
    {
        $disk = config('filesystems.default');
        $fields = [];

        if ($disk === 's3') {
            $fields[] = Forms\Components\Placeholder::make('uploadFolder')
                ->label('Upload Folder')
                ->content(config('filesystems.disks.s3.upload_folder', 'sd_develop'))
                ->columnSpan(1);

            $fields[] = Forms\Components\Placeholder::make('bucket')
                ->label('Bucket')
                ->content(config('filesystems.disks.s3.bucket', ''))
                ->columnSpan(1);

            $fields[] = Forms\Components\Placeholder::make('region')
                ->label('Region')
                ->content(config('filesystems.disks.s3.region', ''))
                ->columnSpan(1);
        } else {
            $diskUrl = config("filesystems.disks.{$disk}.url", '');
            $fields[] = Forms\Components\Placeholder::make('url')
                ->label('URL')
                ->content($diskUrl)
                ->columnSpan(2);
        }

        return $fields;
    }
}
