<x-filament::widget>
    <form wire:submit.prevent="submit">

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                {{ $this->form }}
            </div>
            <div class="flex items-end">
                <x-filament::button type="submit">
                    Filter Albums
                </x-filament::button>
            </div>
        </div>

    </form>
    <br>
    <div class="mb-4">
        <a href="{{ route('filament.admin.resources.albums.create') }}">
            <x-filament::button>
                New Album
            </x-filament::button>
        </a>
    </div>
    <div class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-4">
        @foreach ($albums as $album)
            <a href="{{ route('filament.admin.resources.albums.view', $album->id) }}" class="block">
                <x-filament::card class="relative p-4 bg-white shadow-lg rounded-lg flex flex-col items-center h-full">
                    <div class="flex flex-col items-center flex-1">
                        <p class="text-sm text-gray-600 mb-2">
                            <strong>ID:</strong> {{ $album->id }}
                            | <strong>Created:</strong> {{ $album->created_at->timezone('America/Santiago')->format('d M Y') }}
                            | <strong>Images:</strong> {{ count($album->thumbnail_urls) }}
                            | <strong>Model:</strong> {{ $album->ckpt_name ?? 'N/A' }}
                            | <strong>Seed:</strong> {{ $album->seed ?? 'N/A' }}
                            | <strong>Dimensions:</strong> {{ ($album->width ?? 'N/A') . ' x ' . ($album->height ?? 'N/A') }}
                        </p>
                        <div class="flex flex-wrap justify-center gap-4" style="min-height: 220px; display: flex; align-items: center;">
                            @if (count($album->thumbnail_urls) > 0)
                                <div class="flex justify-center">
                                    <img src="{{ $album->thumbnail_urls[array_rand($album->thumbnail_urls)] }}" alt="Album Thumbnail"
                                        class="max-w-full h-auto" style="max-width: 200px; max-height: 200px;" />
                                </div>
                            @endif
                        </div>
                    </div>
                </x-filament::card>
            </a>
        @endforeach
    </div>
</x-filament::widget>
