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
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Laravel\Facades\Image as InterventionImage;

class AlbumResource extends Resource
{
    protected static ?string $model = Album::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

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
                                // Add prefix if not already present
                                if (strpos($path, $uploadFolder) === false) {
                                    $formatted[] = "{$uploadFolder}/{$path}";
                                } else {
                                    $formatted[] = $path;
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
                                $normalized[] = str_replace($uploadFolder . '/', '', $path);
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
                            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
                            'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
                            'bmp' => 'image/bmp', 'tiff' => 'image/tiff',
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
                                Forms\Components\RichEditor::make('positive')
                                    ->placeholder('Metadata will be automatically extracted from the first uploaded image')
                                    ->formatStateUsing(fn($state, $record) => $record ? $record->positive : $state)
                                    ->dehydrateStateUsing(fn($state) => \App\Models\Album::encryptValue($state)),
                                Forms\Components\RichEditor::make('negative')
                                    ->placeholder('Metadata will be automatically extracted from the first uploaded image')
                                    ->formatStateUsing(fn($state, $record) => $record ? $record->negative : $state)
                                    ->dehydrateStateUsing(fn($state) => \App\Models\Album::encryptValue($state)),
                            ]),
                    ])
                    ->collapsible()
                    ->collapsed(),

                Forms\Components\Section::make('Generation Parameters')
                    ->schema([
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('seed')
                                    ->numeric()
                                    ->placeholder('Metadata will be automatically extracted from the first uploaded image')
                                    ->formatStateUsing(fn($state, $record) => $record ? $record->seed : $state)
                                    ->dehydrateStateUsing(fn($state) => \App\Models\Album::encryptValue($state)),

                                Forms\Components\TextInput::make('steps')
                                    ->numeric()
                                    ->placeholder('Metadata will be automatically extracted from the first uploaded image')
                                    ->formatStateUsing(fn($state, $record) => $record ? $record->steps : $state)
                                    ->dehydrateStateUsing(fn($state) => \App\Models\Album::encryptValue($state)),

                                Forms\Components\TextInput::make('cfg')
                                    ->numeric()
                                    ->step(0.1)
                                    ->placeholder('Metadata will be automatically extracted from the first uploaded image')
                                    ->formatStateUsing(fn($state, $record) => $record ? $record->cfg : $state)
                                    ->dehydrateStateUsing(fn($state) => \App\Models\Album::encryptValue($state)),

                                Forms\Components\TextInput::make('sampler_name')
                                    ->placeholder('Metadata will be automatically extracted from the first uploaded image')
                                    ->formatStateUsing(fn($state, $record) => $record ? $record->sampler_name : $state)
                                    ->dehydrateStateUsing(fn($state) => \App\Models\Album::encryptValue($state)),

                                Forms\Components\TextInput::make('scheduler')
                                    ->placeholder('Metadata will be automatically extracted from the first uploaded image')
                                    ->formatStateUsing(fn($state, $record) => $record ? $record->scheduler : $state)
                                    ->dehydrateStateUsing(fn($state) => \App\Models\Album::encryptValue($state)),

                                Forms\Components\TextInput::make('denoise')
                                    ->numeric()
                                    ->step(0.01)
                                    ->placeholder('Metadata will be automatically extracted from the first uploaded image')
                                    ->formatStateUsing(fn($state, $record) => $record ? $record->denoise : $state)
                                    ->dehydrateStateUsing(fn($state) => \App\Models\Album::encryptValue($state)),

                                Forms\Components\TextInput::make('width')
                                    ->numeric()
                                    ->placeholder('Metadata will be automatically extracted from the first uploaded image')
                                    ->formatStateUsing(fn($state, $record) => $record ? $record->width : $state)
                                    ->dehydrateStateUsing(fn($state) => \App\Models\Album::encryptValue($state)),

                                Forms\Components\TextInput::make('height')
                                    ->numeric()
                                    ->placeholder('Metadata will be automatically extracted from the first uploaded image')
                                    ->formatStateUsing(fn($state, $record) => $record ? $record->height : $state)
                                    ->dehydrateStateUsing(fn($state) => \App\Models\Album::encryptValue($state)),
                            ]),

                        Forms\Components\TextInput::make('ckpt_name')
                            ->label('Model/Checkpoint')
                            ->columnSpanFull()
                            ->placeholder('Metadata will be automatically extracted from the first uploaded image')
                            ->formatStateUsing(fn($state, $record) => $record ? $record->ckpt_name : $state)
                            ->dehydrateStateUsing(fn($state) => \App\Models\Album::encryptValue($state)),
                        Forms\Components\TextInput::make('loras')
                            ->label('LoRA Names')
                            ->placeholder('Metadata will be automatically extracted from the first uploaded image')
                            ->formatStateUsing(fn($state, $record) => $record ? $record->loras : $state)
                            ->dehydrateStateUsing(fn($state) => \App\Models\Album::encryptValue($state)),
                    ])
                    ->collapsible()
                    ->collapsed(),

                Forms\Components\Section::make('Metadata')
                    ->schema([
                        Forms\Components\RichEditor::make('metadata')
                                    ->columnSpanFull()
                                    ->placeholder('Metadata will be automatically extracted from the first uploaded image')
                                    ->formatStateUsing(fn($state, $record) => $record ? $record->metadata : $state)
                                    ->dehydrateStateUsing(fn($state) => \App\Models\Album::encryptValue($state))
                    ])
                    ->collapsible()
                    ->collapsed(),

                Forms\Components\Section::make('Comment')
                    ->schema([
                        Forms\Components\Textarea::make('comment')
                            ->rows(3)
                            ->columnSpanFull()
                            ->placeholder('Add any notes or comments about this album...')
                            ->formatStateUsing(fn($state, $record) => $record ? $record->comment : $state)
                            ->dehydrateStateUsing(fn($state) => \App\Models\Album::encryptValue($state)),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->searchable()
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
                    ->limit(30)
                    ->getStateUsing(fn($record) => $record ? $record->ckpt_name : ''),
                Tables\Columns\TextColumn::make('seed')
                    ->searchable()
                    ->label('Seed')
                    ->getStateUsing(fn($record) => $record ? $record->seed : ''),
                Tables\Columns\TextColumn::make('dimensions')
                    ->label('Dimensions')
                    ->getStateUsing(fn($record) => ($record->width ?? 'N/A') . ' x ' . ($record->height ?? 'N/A')),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('positive')
                    ->form([
                        Forms\Components\TextInput::make('positive')->label('Positive contains')->disabled()->placeholder('(Under development)'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        if (empty($data['positive'])) {
                            return $query;
                        }

                        return $query->where('positive', 'like', '%' . $data['positive'] . '%');
                    }),

                Filter::make('negative')
                    ->form([
                        Forms\Components\TextInput::make('negative')->label('Negative contains')->disabled()->placeholder('(Under development)'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        if (empty($data['negative'])) {
                            return $query;
                        }

                        return $query->where('negative', 'like', '%' . $data['negative'] . '%');
                    }),
            ])
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
            ->paginated([10, 25, 50, 100, 1000])
            ->defaultPaginationPageOption(10)
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
