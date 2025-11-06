@php
    /**
     * The Filament page view used by App\Filament\Pages\LogViewer
     *
     * This view uses Filament UI components and exposes the page's public
     * properties / methods from the Page class.
     */
@endphp

<x-filament::page>
    <x-filament::card>
        <h2 class="text-lg font-medium">laravel.log</h2>
        <br>
        <div class="mt-4">
            <div style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, 'Roboto Mono', 'Courier New', monospace; max-height:60vh; overflow:auto; padding:1rem; border-radius:0.375rem;"
                class="bg-white-100 text-gray-800 dark:bg-gray-900 dark:text-gray-400">
                <pre style="white-space: pre; margin:0;">{{ $this->logContents }}</pre>
            </div>
        </div>
        <br>
        <div class="mt-4 flex gap-2">
            <a href="{{ url('/admin/logs/download') }}"
                class="inline-flex items-center px-3 py-2 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded">
                Download
            </a>

            <form method="POST" action="{{ url('/admin/logs/clear') }}"
                onsubmit="return confirm('Clear laravel.log? This cannot be undone.')">
                @csrf
                <button type="submit"
                    class="inline-flex items-center px-3 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded">
                    Clear
                </button>
            </form>
        </div>
    </x-filament::card>
</x-filament::page>
