<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\Filament\LogController;
use Filament\Http\Middleware\Authenticate as FilamentAuthenticate;

Route::get('/', function () {
    return redirect('/admin');
});

// Route to stream decrypted full images stored on S3 (with album ID)
Route::get('/albums/image/{albumId}/{filename}', [ImageController::class, 'showImage'])->name('album.image');
// Route to stream decrypted thumbnails when thumbnails are stored encrypted (with album ID)
Route::get('/albums/thumbnail/{albumId}/{filename}', [ImageController::class, 'showThumbnail'])->name('album.thumbnail');

// Simple routes to download / clear the laravel.log file. Protected by Filament's auth middleware.
Route::middleware([FilamentAuthenticate::class])->group(function () {
    Route::get('/admin/logs/download', [LogController::class, 'download'])->name('filament.logs.download');
    Route::post('/admin/logs/clear', [LogController::class, 'clear'])->name('filament.logs.clear');
});
