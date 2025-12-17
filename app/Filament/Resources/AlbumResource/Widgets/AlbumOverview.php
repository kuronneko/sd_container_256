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
    // Persist loaded album IDs so we can append pages without serializing models
    public $loadedAlbumIds = [];

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
        // Load first page into the persistent ID list
        $first = $this->fetchPage(1);
        $this->loadedAlbumIds = $first->pluck('id')->toArray();
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
        // Reload the first page with new filters (store IDs)
        $first = $this->fetchPage(1);
        $this->loadedAlbumIds = $first->pluck('id')->toArray();
    }

    public function loadMore(): void
    {
        sleep(1);
        // Increase the page and fetch only the next page, then append IDs
        $this->page++;
        $new = $this->fetchPage($this->page);
        $newIds = $new->pluck('id')->toArray();
        $this->loadedAlbumIds = array_values(array_merge($this->loadedAlbumIds ?? [], $newIds));

        // Update hasMore based on total count
        $total = $this->buildQuery()->count();
        $this->hasMore = $total > count($this->loadedAlbumIds ?? []);
    }

    public function render(): View
    {
        // Re-query models for the stored IDs to ensure transient preview
        // properties (prepared by the model) are recalculated on each render.
        $ids = $this->loadedAlbumIds ?? [];
        if (empty($ids)) {
            $albums = collect([]);
        } else {
            $models = Album::whereIn('id', $ids)->get()->keyBy('id');
            $albums = collect($ids)
                ->map(fn($id) => $models->get($id))
                ->filter();

            // Prepare preview URLs on each model instance
            $albums->each(fn($album) => $album->prepareSelectedImageUrls());
        }

        return view(static::$view, [
            'albums' => $albums,
            'hasMore' => $this->hasMore,
        ]);
    }

    /**
     * Build the base query used by fetchPage, taking current filters/search into account.
     */
    protected function buildQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = Album::whereBetween('created_at', [$this->startDate, $this->endDate])
            ->orderBy('id', 'desc');

        if (!empty($this->search)) {
            $search = trim((string) $this->search);

            $startDateStr = is_string($this->startDate) ? $this->startDate : $this->startDate->toDateString();
            $endDateStr = is_string($this->endDate) ? $this->endDate : $this->endDate->toDateString();

            $matchingIds = SearchCacheService::searchByQuery(
                $search,
                $startDateStr,
                $endDateStr
            );

            if (empty($matchingIds)) {
                return Album::whereRaw('1 = 0');
            }

            return Album::whereIn('id', $matchingIds)->orderBy('id', 'desc');
        }

        return $query;
    }

    /**
     * Fetch a single page of albums according to current filters.
     */
    protected function fetchPage(int $page)
    {
        $query = $this->buildQuery();

        $albums = $query->skip(($page - 1) * $this->perPage)
            ->take($this->perPage)
            ->get();

        // Prepare per-album selected image URLs using the model helper
        $albums->each(fn($album) => $album->prepareSelectedImageUrls());

        // Update hasMore (caller may overwrite as needed)
        $total = $this->buildQuery()->count();
        $this->hasMore = $total > ($page * $this->perPage);

        return $albums;
    }
}
