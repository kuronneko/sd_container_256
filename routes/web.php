<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ImageController;

Route::get('/', function () {
    return redirect('/admin');
});

// Route to stream decrypted full images stored on S3
Route::get('/albums/{album}/image/{filename}', [ImageController::class, 'showFromS3'])->name('album.image');
