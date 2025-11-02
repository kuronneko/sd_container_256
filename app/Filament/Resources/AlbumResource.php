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
use App\Filament\Resources\AlbumResource\Pages;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\AlbumResource\RelationManagers;
use App\Services\ImageService;

class AlbumResource extends Resource
{
    protected static ?string $model = Album::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                FileUpload::make('images')
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

                        if ($record) {
                            return $isS3
                                ? "{$uploadFolder}/albums/{$record->id}"
                                : "albums/{$record->id}";
                        }
                        return $isS3
                            ? "{$uploadFolder}/albums/temp"
                            : "albums/temp";
                    })
                    ->when(config('filesystems.default') === 's3', fn($component) => $component->visibility('public'))
                    ->formatStateUsing(function ($state) {
                        // Add back the upload_folder prefix for display when using S3
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
                        return $state;
                    })
                    ->dehydrateStateUsing(function ($state) {
                        // Normalize paths by removing upload_folder prefix when saving (both create and edit)
                        if (config('filesystems.default') === 's3' && $state) {
                            $uploadFolder = config('filesystems.disks.s3.upload_folder', 'sd_develop');
                            $normalized = [];
                            foreach ((array) $state as $path) {
                                $normalized[] = str_replace($uploadFolder . '/', '', $path);
                            }
                            return $normalized;
                        }
                        return $state;
                    })
                    ->maxFiles(10)
                    ->columnSpanFull()
                    ->reorderable(),

                Forms\Components\Grid::make(2)
                    ->schema([
                        Forms\Components\RichEditor::make('positive_prompt')
                            ->required(),

                        Forms\Components\RichEditor::make('negative_prompt')
                            ->required(),
                    ]),

                Forms\Components\RichEditor::make('extra_configuration')
                    ->required()
                    ->columnSpanFull(),
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
                ImageColumn::make('thumbnail_urls')
                    ->label('Images')
                    ->square()
                    ->stacked()
                    ->limitedRemainingText()
                    ->limit(3),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
                //->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
                //->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                 Tables\Actions\ViewAction::make()
                    ->modalHeading(fn($record) => "ID: " . $record->id)
                    ->label(''),
                Tables\Actions\EditAction::make()
                    ->label(''),
            ])
/*             ->recordAction(Tables\Actions\ViewAction::class)
            ->recordUrl(null) */
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
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
