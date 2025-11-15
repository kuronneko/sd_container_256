<?php

namespace App\Filament\Resources;

use Filament\Forms;
use Filament\Tables;
use App\Models\Album;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\ViewField;
use Filament\Tables\Columns\ImageColumn;
use Filament\Forms\Components\FileUpload;
use Illuminate\Database\Eloquent\Builder;
use Filament\Tables\Filters\Filter;
use App\Filament\Resources\AlbumResource\Pages;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\AlbumResource\RelationManagers;
use App\Services\ImageService;
use App\Services\MetaDataService;
use App\Services\SearchCacheService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Laravel\Facades\Image as InterventionImage;

class AlbumResource extends Resource
{
    protected static ?string $model = Album::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    /**
     * Format data for display: if array, encode as JSON but remove quotes from string values
     */
    protected static function formatForDisplay($data)
    {
        if (!is_array($data)) {
            return $data;
        }

        // Encode array as JSON
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        // Remove quotes from simple string values (like "dpmpp_2m" becomes dpmpp_2m)
        // Match pattern: ": "value" or [  "value"  ] where value has no special chars or nested quotes
        //$json = preg_replace('/"([a-zA-Z0-9_\-\.]+)"(\s*[,\]\}])/m', '$1$2', $json);

        return $json;
    }

    /**
     * Extract value from id/value structure, handling both array and string formats
     */
    protected static function extractValue($item)
    {
        if (is_array($item) && isset($item['value'])) {
            return $item['value'];
        }
        return $item;
    }

    /**
     * Extract and join all values from an array field with their IDs
     * Useful for displaying id/value pairs in JSON format
     */
    protected static function extractAllValues($items, $separator = "\n")
    {
        if (!is_array($items)) {
            return json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        // Return JSON representation of the entire array with ID and value pairs
        return json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                FileUpload::make('images')
                    ->hint('In creation, metadata will be extracted from the first uploaded image. In edit mode, metadata will be updated from any newly uploaded or modified images.')
                    ->helperText('You can upload multiple images. Supported formats: jpg, png, gif. Max 10 images.')
                    ->image()
                    ->required()
                    ->imageEditor()
                    ->multiple()
                    ->panelLayout('grid')
                    ->openable()
                    ->downloadable()
                    ->disk(config('filesystems.default'))
                    ->directory(function ($record) {
                        $isS3 = config('filesystems.default') === 's3';
                        $uploadFolder = $isS3 ? config('filesystems.disks.s3.upload_folder', 'sd_develop') : '';

                        return $isS3
                            ? "{$uploadFolder}/albums"
                            : "albums";
                    })
                    ->when(config('filesystems.default') === 's3', fn($component) => $component->visibility('public'))
                    ->formatStateUsing(function ($state) {
                        // For S3: add back the upload_folder prefix for display
                        if (config('filesystems.default') === 's3' && $state) {
                            $uploadFolder = config('filesystems.disks.s3.upload_folder', 'sd_develop');
                            $formatted = [];
                            foreach ((array) $state as $path) {
                                // Ensure $path is a string before using strpos
                                if (is_string($path)) {
                                    // Add prefix if not already present
                                    if (strpos($path, $uploadFolder) === false) {
                                        $formatted[] = "{$uploadFolder}/{$path}";
                                    } else {
                                        $formatted[] = $path;
                                    }
                                } else {
                                    // Skip non-string entries
                                    continue;
                                }
                            }
                            return $formatted;
                        }

                        // For local disk, Filament may provide a UUID => path map when reorderable.
                        // Normalize to an indexed array of paths so both S3 and local behave the same in the DB.
                        if ($state) {
                            return array_values((array) $state);
                        }

                        return $state;
                    })
                    ->dehydrateStateUsing(function ($state) {
                        // For S3: remove upload_folder prefix when saving
                        if (config('filesystems.default') === 's3' && $state) {
                            $uploadFolder = config('filesystems.disks.s3.upload_folder', 'sd_develop');
                            $normalized = [];
                            foreach ((array) $state as $path) {
                                // Ensure $path is a string before processing
                                if (is_string($path)) {
                                    $normalized[] = str_replace($uploadFolder . '/', '', $path);
                                }
                            }
                            return $normalized;
                        }

                        // For local disk: drop any UUID keys and return an indexed array of paths
                        if ($state) {
                            return array_values((array) $state);
                        }

                        return $state;
                    })
                    // Ensure Filament's preview/open/download use our decrypting route for full images
                    ->getUploadedFileUsing(function ($component, $file, $storedFileNames) {
                        $disk = config('filesystems.default');
                        $uploadFolder = $disk === 's3' ? config('filesystems.disks.s3.upload_folder', 'sd_develop') : '';

                        // If the stored path contains the upload folder prefix, remove it for normalization
                        $normalized = $file;
                        if ($uploadFolder && strpos($normalized, $uploadFolder . '/') === 0) {
                            $normalized = substr($normalized, strlen($uploadFolder) + 1);
                        }

                        $name = basename($file);
                        $type = null;
                        $url = null;

                        // Infer MIME type from file extension (encrypted objects don't report image/* MIME)
                        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                        $mimeMap = [
                            'jpg' => 'image/jpeg',
                            'jpeg' => 'image/jpeg',
                            'png' => 'image/png',
                            'gif' => 'image/gif',
                            'webp' => 'image/webp',
                            'svg' => 'image/svg+xml',
                            'bmp' => 'image/bmp',
                            'tiff' => 'image/tiff',
                        ];
                        $type = $mimeMap[$ext] ?? 'image/jpeg';

                        // If this looks like an album file (albums/albumId/filename), route to our decrypt controller
                        $segments = explode('/', $normalized);
                        if (isset($segments[0]) && $segments[0] === 'albums' && isset($segments[1])) {
                            $albumId = $segments[1];
                            $filename = basename($file);
                            $url = url("/albums/image/{$albumId}/{$filename}");
                        }

                        // Fallback to storage URL if we couldn't build decrypt route
                        if (!$url) {
                            try {
                                $url = $component->getVisibility() === 'private'
                                    ? $component->getDisk()->temporaryUrl($file, now()->addMinutes(5))
                                    : $component->getDisk()->url($file);
                            } catch (\Throwable $e) {
                                $url = $component->getDisk()->url($file);
                            }
                        }

                        return [
                            'name' => $storedFileNames[$file] ?? $name,
                            'size' => 0,
                            'type' => $type,
                            'url' => $url,
                        ];
                    })
                    ->saveUploadedFileUsing(function ($component, $file) {
                        // $file is a Livewire TemporaryUploadedFile
                        $diskName = $component->getDiskName();
                        $directory = trim($component->getDirectory(), '/');
                        $fileName = $component->getUploadedFileNameForStorage($file);
                        $path = trim($directory . '/' . $fileName, '/');

                        if (ImageService::isEncryptedDisk($diskName)) {
                            try {
                                // Read plaintext file content
                                $realPath = method_exists($file, 'getRealPath') ? $file->getRealPath() : ($file->getPath() ?? null);
                                $contents = $realPath ? file_get_contents($realPath) : $file->get();

                                // Extract metadata from plaintext image before encryption
                                // Store metadata in session for processing during album create/update
                                // In create mode: captures metadata from all uploaded images (first will be used)
                                // In edit mode: captures metadata from newly uploaded/modified images for update
                                $metadata = MetaDataService::extractMetadataFromContent($contents);
                                if ($metadata) {
                                    session()->put("image_metadata_{$fileName}", $metadata);
                                }

                                // Generate and encrypt thumbnail if configured
                                if (config('image_encrypt.encrypt_thumbnails', false)) {
                                    try {
                                        $image = InterventionImage::read($contents);
                                        $image->cover(200, 200);
                                        $thumbnailContent = $image->toJpeg();
                                        $encryptedThumbnail = Crypt::encryptString($thumbnailContent);

                                        $uploadFolder = $diskName === 's3' ? config('filesystems.disks.s3.upload_folder', 'sd_develop') : '';
                                        $baseThumbPath = $uploadFolder ? "{$uploadFolder}/albums/thumbnails/{$fileName}" : "albums/thumbnails/{$fileName}";
                                        $component->getDisk()->put($baseThumbPath, $encryptedThumbnail, ['visibility' => $component->getVisibility()]);
                                    } catch (\Exception $e) {
                                        // Silently skip thumbnail generation on error
                                    }
                                }

                                // Encrypt and upload image
                                $encrypted = Crypt::encryptString($contents);
                                $component->getDisk()->put($path, $encrypted, ['visibility' => $component->getVisibility()]);
                                return $path;
                            } catch (\Exception $e) {
                                return null;
                            }
                        }

                        // For non-encrypted disks, use normal storage
                        try {
                            $storeMethod = $component->getVisibility() === 'public' ? 'storePubliclyAs' : 'storeAs';
                            return $file->{$storeMethod}($directory, $fileName, $diskName);
                        } catch (\Throwable $e) {
                            return null;
                        }
                    })
                    ->maxFiles(10)
                    ->columnSpanFull()
                    ->reorderable(),

                Forms\Components\Section::make('Prompts')
                    ->schema([
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\Textarea::make('positive')
                                    ->autosize()
                                    ->placeholder('Metadata will be automatically extracted from the first uploaded image')
                                    ->formatStateUsing(fn($state, $record) => $record && !empty($record->positive) ? self::formatForDisplay($record->positive) : $state),
                                Forms\Components\Textarea::make('negative')
                                    ->autosize()
                                    ->placeholder('Metadata will be automatically extracted from the first uploaded image')
                                    ->formatStateUsing(fn($state, $record) => $record && !empty($record->negative) ? self::formatForDisplay($record->negative) : $state),
                            ]),
                    ])
                    ->collapsible()
                    ->collapsed(),

                Forms\Components\Section::make('Generation Parameters')
                    ->schema([
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\Textarea::make('seed')

                                    ->autosize()
                                    ->placeholder('Metadata will be automatically extracted from the first uploaded image')
                                    ->formatStateUsing(fn($state, $record) => $record && !empty($record->seed) ? self::formatForDisplay($record->seed) : $state),

                                Forms\Components\Textarea::make('steps')

                                    ->autosize()
                                    ->placeholder('Metadata will be automatically extracted from the first uploaded image')
                                    ->formatStateUsing(fn($state, $record) => $record && !empty($record->steps) ? self::formatForDisplay($record->steps) : $state),

                                Forms\Components\Textarea::make('cfg')

                                    ->autosize()
                                    ->placeholder('Metadata will be automatically extracted from the first uploaded image')
                                    ->formatStateUsing(fn($state, $record) => $record && !empty($record->cfg) ? self::formatForDisplay($record->cfg) : $state),

                                Forms\Components\Textarea::make('sampler_name')

                                    ->autosize()
                                    ->placeholder('Metadata will be automatically extracted from the first uploaded image')
                                    ->formatStateUsing(fn($state, $record) => $record && !empty($record->sampler_name) ? self::formatForDisplay($record->sampler_name) : $state),

                                Forms\Components\Textarea::make('scheduler')

                                    ->autosize()
                                    ->placeholder('Metadata will be automatically extracted from the first uploaded image')
                                    ->formatStateUsing(fn($state, $record) => $record && !empty($record->scheduler) ? self::formatForDisplay($record->scheduler) : $state),

                                Forms\Components\Textarea::make('denoise')

                                    ->autosize()
                                    ->placeholder('Metadata will be automatically extracted from the first uploaded image')
                                    ->formatStateUsing(fn($state, $record) => $record && !empty($record->denoise) ? self::formatForDisplay($record->denoise) : $state),

                                Forms\Components\Textarea::make('width')

                                    ->autosize()
                                    ->placeholder('Metadata will be automatically extracted from the first uploaded image')
                                    ->formatStateUsing(fn($state, $record) => $record && !empty($record->width) ? self::formatForDisplay($record->width) : $state),

                                Forms\Components\Textarea::make('height')

                                    ->autosize()
                                    ->placeholder('Metadata will be automatically extracted from the first uploaded image')
                                    ->formatStateUsing(fn($state, $record) => $record && !empty($record->height) ? self::formatForDisplay($record->height) : $state),
                            ]),

                        Forms\Components\Textarea::make('ckpt_name')
                            ->label('Model/Checkpoint')

                            ->autosize()
                            ->columnSpanFull()
                            ->placeholder('Metadata will be automatically extracted from the first uploaded image')
                            ->formatStateUsing(fn($state, $record) => $record && !empty($record->ckpt_name) ? self::formatForDisplay($record->ckpt_name) : $state),
                        Forms\Components\Textarea::make('loras')
                            ->label('LoRA Names')

                            ->autosize()
                            ->placeholder('Metadata will be automatically extracted from the first uploaded image')
                            ->formatStateUsing(fn($state, $record) => $record && !empty($record->loras) ? self::formatForDisplay($record->loras) : $state),
                    ])
                    ->collapsible()
                    ->collapsed(),

                Forms\Components\Section::make('Metadata')
                    ->schema([
                        Forms\Components\Textarea::make('metadata')
                            ->autosize()
                            ->columnSpanFull()
                            ->placeholder('Metadata will be automatically extracted from the first uploaded image')
                            ->formatStateUsing(function ($state, $record) {
                                if ($record && !empty($record->metadata)) {
                                    // metadata is always an array from decryptArray()
                                    return json_encode($record->metadata);
                                    //return self::formatForDisplay($record->metadata);
                                }
                                return $state;
                            })
                    ])
                    ->collapsible()
                    ->collapsed(),

                Forms\Components\Section::make('Comment')
                    ->schema([
                        Forms\Components\Textarea::make('comment')
                            ->autosize()
                            ->columnSpanFull()
                            ->placeholder('Add any notes or comments about this album...'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->sortable()
                    ->label('ID'),
                ImageColumn::make('prepared_thumbnail_url')
                    ->label('Images')
                    ->getStateUsing(function ($record) {
                        // Ensure the model prepares a selected thumbnail for display
                        if (method_exists($record, 'prepareSelectedImageUrls')) {
                            $record->prepareSelectedImageUrls();
                        }

                        // Return an array so ImageColumn stacked/limit behave consistently.
                        if (!empty($record->selected_thumbnail_url)) {
                            return [$record->selected_thumbnail_url];
                        }

                        return [];
                    })
                    ->extraImgAttributes(function ($record) {
                        // Ensure prepared url exists
                        if (method_exists($record, 'prepareSelectedImageUrls')) {
                            $record->prepareSelectedImageUrls();
                        }

                        $attrs = [];
                        if (!empty($record->selected_image_url)) {
                            // Use inline JS to open the selected image in a new tab
                            $attrs['onclick'] = "window.open('{$record->selected_image_url}', '_blank')";
                            $attrs['style'] = 'cursor: pointer;';
                            $attrs['title'] = 'Open image in new tab';
                        }

                        return $attrs;
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->label('Created'),
                Tables\Columns\TextColumn::make('ckpt_name')
                    ->label('Model')
                    ->searchable()
                    ->html() // Permitir saltos de línea en HTML
                    ->getStateUsing(function ($record) {
                        if ($record && !empty($record->ckpt_name) && is_array($record->ckpt_name)) {
                            $items = array_map(function ($i) {
                                return self::extractValue($i);
                            }, $record->ckpt_name);
                            return implode("<br>", array_filter($items, fn($v) => $v !== null && $v !== ''));
                        }
                        return '';
                    }),

                Tables\Columns\TextColumn::make('seed')
                    ->label('Seed')
                    ->searchable()
                    ->html()
                    ->getStateUsing(function ($record) {
                        if ($record && !empty($record->seed) && is_array($record->seed)) {
                            $items = array_map(function ($i) {
                                return self::extractValue($i);
                            }, $record->seed);
                            return implode("<br>", array_filter($items, fn($v) => $v !== null && $v !== ''));
                        }
                        return '';
                    }),

                Tables\Columns\TextColumn::make('dimensions')
                    ->label('Dimensions')
                    ->html()
                    ->getStateUsing(function ($record) {
                        $widths = is_array($record->width) ? $record->width : [];
                        $heights = is_array($record->height) ? $record->height : [];

                        $max = max(count($widths), count($heights), 1);
                        $lines = [];

                        for ($i = 0; $i < $max; $i++) {
                            $w = $widths[$i] ?? null;
                            $h = $heights[$i] ?? null;

                            $wVal = $w !== null ? self::extractValue($w) : 'N/A';
                            $hVal = $h !== null ? self::extractValue($h) : 'N/A';

                            $lines[] = $wVal . ' x ' . $hVal;
                        }

                        return implode("<br>", $lines);
                    }),

                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label(''),
                Tables\Actions\EditAction::make()
                    ->label(''),
            ])
            // Disable row click/navigation: ensure clicking a table row does not open the record.
            ->recordAction(null)
            ->recordUrl(null)
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->paginated([9, 25, 50, 100, 1000])
            ->defaultPaginationPageOption(9)
            ->defaultSort('id', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAlbums::route('/'),
            'create' => Pages\CreateAlbum::route('/create'),
            'edit' => Pages\EditAlbum::route('/{record}/edit'),
            'view' => Pages\ViewAlbum::route('/{record}'),
        ];
    }
}
