<?php

namespace App\Providers;

use App\Models\Album;
use App\Observers\AlbumObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Album::observe(AlbumObserver::class);
        // Ensure Livewire writes temporary uploaded files to the local 'public' disk
        // so that Livewire's temporary folder (livewire-temp) does not appear on S3.
        // This sets the runtime config value; if you prefer a published config file,
        // create config/livewire.php and set temporary_file_upload.disk = 'public'.
        if (!config('livewire.temporary_file_upload.disk')) {
            config(['livewire.temporary_file_upload.disk' => 'public']);
        }
    }
}
