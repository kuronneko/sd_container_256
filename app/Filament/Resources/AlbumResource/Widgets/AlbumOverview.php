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
        $this->albums = Album::whereBetween('created_at', [$this->startDate, $this->endDate])->get();
    }
}
