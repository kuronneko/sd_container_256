<x-filament::widget>

    <div>
        @livewire(\App\Filament\Resources\AlbumResource\Widgets\FilesystemOverview::class)
    </div>

    <br>

    <form wire:submit.prevent="submit">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                {{ $this->form }}
            </div>
            <div class="flex items-end">
                <x-filament::button type="submit">
                    Filter Albums
                </x-filament::button>
                <div>
                    <a class="p-4" href="{{ route('filament.admin.resources.albums.create') }}">
                        <x-filament::button>
                            New Album
                        </x-filament::button>
                    </a>
                </div>
            </div>
        </div>
    </form>

    <br>

    <div class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-4">
        @foreach ($albums as $album)
            <x-filament::card class="relative p-4 bg-white shadow-lg rounded-lg flex flex-col items-center h-full">
                <div class="flex flex-col items-center flex-1">
                    <p class="text-sm text-gray-600 mb-2">
                        <a href="{{ route('filament.admin.resources.albums.view', $album->id) }}" class="block">
                            @if (!empty($album->selected_image_url))
                                <button type="button" aria-label="Open image in new tab"
                                    onclick="window.open('{{ $album->selected_image_url }}', '_blank')">
                                    <strong>ID:</strong> {{ $album->id }}
                                </button>
                            @endif
                            |
                            <strong>Model:</strong>
                            {{ $album->metadataAt('ckpt_name', $album->selected_image_url ? $album->indexForFilename(basename($album->selected_image_url)) ?? 0 : 0) ?? 'N/A' }}
                            |
                            <strong>Seed:</strong>
                            {{ $album->metadataAt('seed', $album->selected_image_url ? $album->indexForFilename(basename($album->selected_image_url)) ?? 0 : 0) ?? 'N/A' }}
                            |
                            <strong>Dimensions:</strong>
                            {{ ($album->metadataAt('width', $album->selected_image_url ? $album->indexForFilename(basename($album->selected_image_url)) ?? 0 : 0) !== null ? $album->metadataAt('width', $album->selected_image_url ? $album->indexForFilename(basename($album->selected_image_url)) ?? 0 : 0) : 'N/A') . 'x' . ($album->metadataAt('height', $album->selected_image_url ? $album->indexForFilename(basename($album->selected_image_url)) ?? 0 : 0) !== null ? $album->metadataAt('height', $album->selected_image_url ? $album->indexForFilename(basename($album->selected_image_url)) ?? 0 : 0) : 'N/A') }}
                            |
                            <strong>Filename:</strong>
                            {{ basename($album->selected_image_url ?? '') }}
                        </a>
                    </p>
                    <div class="flex flex-wrap justify-center gap-4"
                        style="min-height: 220px; display: flex; align-items: center;">
                        @if ($album->count_images > 0)
                            @if (!empty($album->selected_thumbnail_url))
                                <div class="flex justify-center">
                                    <button type="button" aria-label="Open image in new tab"
                                        onclick="window.open('{{ $album->selected_image_url }}', '_blank')"
                                        class="p-0 border-0 bg-transparent">
                                        <img src="{{ $album->selected_thumbnail_url }}" alt="Album Thumbnail"
                                            class="max-w-full h-auto" style="max-width: 200px; max-height: 200px;" />
                                    </button>
                                </div>
                            @endif
                        @endif
                    </div>
                </div>
            </x-filament::card>
        @endforeach
    </div>

    <br>

    @if (!empty($hasMore) && $hasMore)
        <div class="flex justify-center mt-4">
            <x-filament::button wire:click="loadMore" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="loadMore">Load more</span>
                <span wire:loading wire:target="loadMore">Load more</span>
            </x-filament::button>
        </div>
    @endif
</x-filament::widget>
