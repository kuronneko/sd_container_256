<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\File;

class LogViewer extends Page
{
    protected static string $view = 'filament.pages.log-viewer';

    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $navigationLabel = 'Logs';
    protected static ?int $navigationSort = 100;

    public $logContents = '';

    public function mount(): void
    {
        $path = storage_path('logs/laravel.log');

        if (File::exists($path)) {
            // limit size to avoid loading extremely large files into memory
            $contents = File::get($path);
            $this->logContents = $contents;
        } else {
            $this->logContents = "Log file not found at: {$path}";
        }
    }
}
