<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ImageController;

Route::get('/', function () {
    return redirect('/admin');
});

// Route to stream decrypted full images stored on S3
Route::get('/albums/{album}/image/{filename}', [ImageController::class, 'showImage'])->name('album.image');
// Route to stream decrypted thumbnails when thumbnails are stored encrypted
Route::get('/albums/{album}/thumbnail/{filename}', [ImageController::class, 'showThumbnail'])->name('album.thumbnail');
