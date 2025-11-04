<?php

namespace App\Filament\Resources\AlbumResource\Widgets;

use App\Models\Album;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Widgets\Widget;
use Illuminate\Contracts\View\View;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Concerns\InteractsWithForms;

class AlbumOverview extends Widget implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'filament.resources.album-resource.widgets.album-overview';

    protected int | string | array $columnSpan = 'full';

    public $startDate;
    public $endDate;
    public int $perPage = 100;
    public int $page = 1;
    public bool $hasMore = true;

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

        $this->page = 1;
        $this->hasMore = true;
    }

    public function submit(): void
    {
        sleep(1);

        $this->startDate = $this->form->getState()['startDate'];
        $this->endDate = $this->form->getState()['endDate'];

        // Reset pagination when filters change
        $this->page = 1;
        $this->hasMore = true;
    }

    public function loadMore(): void
    {
        sleep(1);
        // simply increase the page; render() will fetch the correct items
        $this->page++;
    }

    public function render(): View
    {
        $query = Album::whereBetween('created_at', [$this->startDate, $this->endDate])
            ->orderBy('created_at', 'desc');

        $total = $query->count();

        $albums = $query->skip(0)->take($this->page * $this->perPage)->get();

        $this->hasMore = $total > $albums->count();

        return view(static::$view, [
            'albums' => $albums,
            'hasMore' => $this->hasMore,
        ]);
    }
}
