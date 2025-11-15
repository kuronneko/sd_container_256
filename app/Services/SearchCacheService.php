<?php

namespace App\Services;

use App\Models\Album;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SearchCacheService
{
    private const CACHE_PREFIX = 'album_search_';
    private const CACHE_TTL = 60 * 60 * 24; // 24 hours

    /**
     * Search albums by decrypting all fields and looking for matches
     * Results are cached for subsequent searches
     *
     * @param string $query Search term
     * @param string $startDate Start date for filtering
     * @param string $endDate End date for filtering
     * @return array Array of matching album IDs
     */
    public static function searchByQuery(string $query, string $startDate, string $endDate): array
    {
        $trimmedQuery = trim($query);

        if (empty($trimmedQuery)) {
            return [];
        }

        // Create cache key based on query and date range
        $cacheKey = self::CACHE_PREFIX . md5($trimmedQuery . $startDate . $endDate);

        // Try to get from cache first
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            Log::debug('Search results retrieved from cache', ['query' => $trimmedQuery]);
            return $cached;
        }

        Log::debug('Cache miss, decrypting all albums to search', ['query' => $trimmedQuery]);

        $matchingIds = [];
        $albums = Album::whereBetween('created_at', [$startDate, $endDate])->get();

        foreach ($albums as $album) {
            if (self::matchesSearch($album, $trimmedQuery)) {
                $matchingIds[] = $album->id;
            }
        }

        // Cache the results
        Cache::put($cacheKey, $matchingIds, self::CACHE_TTL);
        Log::debug('Search results cached', ['query' => $trimmedQuery, 'matches' => count($matchingIds)]);

        return $matchingIds;
    }

    /**
     * Check if an album matches the search query
     * Decrypts and checks: positive, negative, ckpt_name, seed
     */
    private static function matchesSearch(Album $album, string $query): bool
    {
        $lowerQuery = strtolower($query);

        // Get all searchable fields (they auto-decrypt via accessors)
        $searchFields = [
            $album->positive ?? '',
            $album->negative ?? '',
            $album->ckpt_name ?? '',
            $album->seed ?? '',
        ];

        foreach ($searchFields as $field) {
            // Handle array fields (convert to string)
            if (is_array($field)) {
                $field = implode(' ', array_map(fn($item) => is_array($item) ? json_encode($item) : (string)$item, $field));
            } else {
                $field = (string)$field;
            }

            // Case-insensitive substring match
            if (stripos($field, $query) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Clear cache for a specific search
     */
    public static function clearSearchCache(string $query, string $startDate, string $endDate): void
    {
        $cacheKey = self::CACHE_PREFIX . md5($query . $startDate . $endDate);
        Cache::forget($cacheKey);
        Log::debug('Search cache cleared', ['query' => $query]);
    }

    /**
     * Clear all search cache
     */
    public static function clearAllCache(): void
    {
        // Since we're using a prefix, we'd need to flush all or use a tag-based approach
        // For now, we'll rely on TTL, but this can be improved with Cache tags
        Log::info('Clearing all search cache');
    }
}
