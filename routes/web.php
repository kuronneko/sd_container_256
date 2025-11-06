<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ImageController;

Route::get('/', function () {
    return redirect('/admin');
});

// Route to stream decrypted full images stored on S3 (with album ID)
Route::get('/albums/image/{albumId}/{filename}', [ImageController::class, 'showImage'])->name('album.image');
// Route to stream decrypted thumbnails when thumbnails are stored encrypted (with album ID)
Route::get('/albums/thumbnail/{albumId}/{filename}', [ImageController::class, 'showThumbnail'])->name('album.thumbnail');
