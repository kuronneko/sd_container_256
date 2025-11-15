<?php

namespace App\Filament\Resources\AlbumResource\Pages;

use App\Filament\Resources\AlbumResource;
use App\Services\SearchCacheService;
use Carbon\Carbon;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListAlbums extends ListRecords
{
    protected static string $resource = AlbumResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    /**
     * Override the query builder filter to handle searches on encrypted fields
     * Uses SearchCacheService for cache-first search instead of LIKE
     */
    public function filterTableQuery(Builder $query): Builder
    {
        // Get the search term
        $search = $this->getTableSearch();

        if (!empty($search)) {
            $search = trim((string) $search);

            // Use SearchCacheService with same date range as AlbumOverview
            // Format dates as Y-m-d strings like AlbumOverview does
            $startDateStr = Carbon::now()->startOfYear()->toDateString();
            $endDateStr = Carbon::now()->endOfYear()->toDateString();

            // Use SearchCacheService to find matching album IDs
            $matchingIds = SearchCacheService::searchByQuery(
                $search,
                $startDateStr,
                $endDateStr
            );

            if (empty($matchingIds)) {
                return $query->whereRaw('1 = 0');
            }

            return $query->whereIn('id', $matchingIds);
        }

        // If no search, still apply other filters from parent (but not search-related ones)
        return parent::filterTableQuery($query);
    }
}
