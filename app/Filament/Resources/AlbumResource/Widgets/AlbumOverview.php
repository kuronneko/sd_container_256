<?php

namespace App\Filament\Resources\AlbumResource\Widgets;

use App\Models\Album;
use App\Services\SearchCacheService;
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
    public ?string $search = null;
    public int $perPage = 9;
    public int $page = 1;
    public bool $hasMore = true;

    protected function getFormSchema(): array
    {
        return [
            Forms\Components\Grid::make(2)
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
                ]),
            Forms\Components\Grid::make()
                ->schema([
                    Forms\Components\TextInput::make('search')
                        ->label('Search')
                        ->placeholder('Search positive, negative, seed or model (ckpt_name) - decrypts on first search, then cached')
                        ->columnSpan(2),
                ]),
        ];
    }

    public function mount(): void
    {
        $this->form->fill([
            'startDate' => Carbon::now()->startOfYear(),
            'endDate' => Carbon::now()->endOfYear(),
            'search' => null,
        ]);

        $this->page = 1;
        $this->hasMore = true;
    }

    public function submit(): void
    {
        sleep(1);

        $this->startDate = $this->form->getState()['startDate'];
        $this->endDate = $this->form->getState()['endDate'];
        $this->search = $this->form->getState()['search'] ?? null;

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
            ->orderBy('id', 'desc');

        if (!empty($this->search)) {
            $search = trim((string) $this->search);

            // Use search cache service to find matching album IDs
            // First search decrypts all fields, subsequent searches use cache
            $startDateStr = is_string($this->startDate) ? $this->startDate : $this->startDate->toDateString();
            $endDateStr = is_string($this->endDate) ? $this->endDate : $this->endDate->toDateString();

            $matchingIds = SearchCacheService::searchByQuery(
                $search,
                $startDateStr,
                $endDateStr
            );

            if (empty($matchingIds)) {
                $query = Album::whereRaw('1 = 0'); // Return empty result
            } else {
                $query = Album::whereIn('id', $matchingIds)->orderBy('id', 'desc');
            }
        }

        $total = $query->count();

        $albums = $query->skip(0)->take($this->page * $this->perPage)->get();

        // Prepare per-album selected image URLs using the model helper
        $albums->each(fn($album) => $album->prepareSelectedImageUrls());

        $this->hasMore = $total > $albums->count();

        return view(static::$view, [
            'albums' => $albums,
            'hasMore' => $this->hasMore,
        ]);
    }
}
