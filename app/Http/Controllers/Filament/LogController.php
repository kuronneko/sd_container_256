<?php

namespace App\Http\Controllers\Filament;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;

class LogController extends Controller
{
    public function download()
    {
        $path = storage_path('logs/laravel.log');

        if (! File::exists($path)) {
            abort(404, 'Log file not found');
        }

        return response()->download($path, 'laravel.log');
    }

    public function clear(): RedirectResponse
    {
        $path = storage_path('logs/laravel.log');

        if (File::exists($path)) {
            // truncate file
            File::put($path, '');
        }

        return redirect()->back();
    }
}
