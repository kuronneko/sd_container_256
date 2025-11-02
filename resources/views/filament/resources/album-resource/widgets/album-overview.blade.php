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
    <div class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-4">
        @foreach ($albums as $album)
            <a href="{{ route('filament.admin.resources.albums.view', $album->id) }}" class="block">
                <x-filament::card class="relative p-4 bg-white shadow-lg rounded-lg flex flex-col items-center">
                    <div class="absolute top-0 right-0 p-2">
                        <p class="text-xs text-gray-500">Created At:
                            {{ $album->created_at->timezone('America/Santiago')->format('d M Y, H:i') }}</p>
                    </div>
                    <div class="flex flex-col items-center">
                        <p class="text-sm mb-2">
                            <strong>ID:</strong>
                            {{ $album->id }}
                        </p>
                        <div class="flex flex-wrap justify-center gap-4">
                            @foreach (collect($album->thumbnail_urls)->take(3) as $thumbnail)
                                <div class="flex justify-center">
                                    <img src="{{ $thumbnail }}" alt="Album Thumbnail"
                                        class="max-w-full h-auto" style="max-width: {{ collect($album->thumbnail_urls)->count() === 3 ? '100px' : (collect($album->thumbnail_urls)->count() === 2 ? '150px' : '200px') }}; max-height: {{ collect($album->thumbnail_urls)->count() === 3 ? '100px' : (collect($album->thumbnail_urls)->count() === 2 ? '150px' : '200px') }};" />
                                </div>
                            @endforeach
                        </div>
                    </div>
                </x-filament::card>
            </a>
        @endforeach
    </div>
</x-filament::widget>
