<?php

namespace App\Filament\Resources\AlbumResource\Widgets;

use App\Models\Album;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Widgets\Widget;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Concerns\InteractsWithForms;

class AlbumOverview extends Widget implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'filament.resources.album-resource.widgets.album-overview';

    protected int | string | array $columnSpan = 'full';

    public $startDate;
    public $endDate;
    public $albums;

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
                        ->columns(2),
                ])
                ->columnSpan('full'),
            Forms\Components\Grid::make()
                ->schema([
                    Forms\Components\DatePicker::make('startDate')
                        ->label('Start date')
                        ->default(Carbon::now()->startOfMonth())
                        ->required()
                        ->columnSpan(1),
                    Forms\Components\DatePicker::make('endDate')
                        ->label('End date')
                        ->default(Carbon::now()->endOfMonth())
                        ->required()
                        ->columnSpan(1),
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

    public function mount(): void
    {
        $this->form->fill([
            'startDate' => Carbon::now()->startOfYear(),
            'endDate' => Carbon::now()->endOfYear(),
        ]);

        $this->loadAlbums();
    }

    public function submit(): void
    {
        $this->startDate = $this->form->getState()['startDate'];
        $this->endDate = $this->form->getState()['endDate'];

        $this->loadAlbums();
    }

    protected function loadAlbums(): void
    {
        $this->albums = Album::whereBetween('created_at', [$this->startDate, $this->endDate])
            ->orderBy('created_at', 'desc')
            ->get();
    }
}
