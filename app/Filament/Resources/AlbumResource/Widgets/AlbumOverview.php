<?php

namespace App\Filament\Resources\AlbumResource\Widgets;

use App\Models\Album;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Widgets\Widget;
use Illuminate\Contracts\View\View;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Concerns\InteractsWithForms;

class AlbumOverview extends Widget implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'filament.resources.album-resource.widgets.album-overview';

    protected int | string | array $columnSpan = 'full';

    public $startDate;
    public $endDate;
    public int $perPage = 100;
    public int $page = 1;
    public bool $hasMore = true;

    protected function getFormSchema(): array
    {
        return [
            Forms\Components\Grid::make()
                ->schema([
                    Forms\Components\DatePicker::make('startDate')
                        ->label('Start date')
                        ->default(Carbon::now()->startOfMonth())
                        ->required()
                        ->columnSpan(1),
                    Forms\Components\DatePicker::make('endDate')
                        ->label('End date')
                        ->default(Carbon::now()->endOfMonth())
                        ->required()
                        ->columnSpan(1),
                ])
                ->columnSpan('full'),
        ];
    }

    public function mount(): void
    {
        $this->form->fill([
            'startDate' => Carbon::now()->startOfYear(),
            'endDate' => Carbon::now()->endOfYear(),
        ]);

        $this->page = 1;
        $this->hasMore = true;
    }

    public function submit(): void
    {
        sleep(1);

        $this->startDate = $this->form->getState()['startDate'];
        $this->endDate = $this->form->getState()['endDate'];

        // Reset pagination when filters change
        $this->page = 1;
        $this->hasMore = true;
    }

    public function loadMore(): void
    {
        sleep(1);
        // simply increase the page; render() will fetch the correct items
        $this->page++;
    }

    public function render(): View
    {
        $query = Album::whereBetween('created_at', [$this->startDate, $this->endDate])
            ->orderBy('created_at', 'desc');

        $total = $query->count();

        $albums = $query->skip(0)->take($this->page * $this->perPage)->get();

        // Attach selected thumbnail/full URLs to each album for consistent display/open behavior
        $this->attachSelectedImageUrls($albums);

        $this->hasMore = $total > $albums->count();

        return view(static::$view, [
            'albums' => $albums,
            'hasMore' => $this->hasMore,
        ]);
    }

    /**
     * Pick a random image per album and attach selected_thumbnail_url and selected_image_url
     * properties on the album model instances so the view can use the same image for
     * the thumbnail and the 'open' action.
     *
     * @param \Illuminate\Support\Collection $albums
     * @return void
     */
    protected function attachSelectedImageUrls($albums): void
    {
        foreach ($albums as $album) {
            $images = $album->images ?? [];
            $selectedThumb = null;
            $selectedFull = null;

            if (count($images) > 0) {
                $randKey = array_rand($images);
                $filename = basename($images[$randKey]);

                if (config('filesystems.default') === 's3') {
                    $uploadFolder = config('filesystems.disks.s3.upload_folder', 'sd_develop');
                    $bucket = config('filesystems.disks.s3.bucket');
                    $region = config('filesystems.disks.s3.region');
                    $cdnUrl = "https://{$bucket}.{$region}.cdn.digitaloceanspaces.com";

                    $selectedThumb = "{$cdnUrl}/{$uploadFolder}/albums/{$album->id}/thumbnails/{$filename}";
                    $selectedFull = "{$cdnUrl}/{$uploadFolder}/albums/{$album->id}/{$filename}";
                } else {
                    $disk = config('filesystems.default');
                    $diskUrl = config("filesystems.disks.{$disk}.url");
                    if ($diskUrl) {
                        $selectedThumb = rtrim($diskUrl, '/') . "/albums/{$album->id}/thumbnails/{$filename}";
                        $selectedFull = rtrim($diskUrl, '/') . "/albums/{$album->id}/{$filename}";
                    } else {
                        $selectedThumb = url('/storage/app/private/albums/' . $album->id . '/thumbnails/' . $filename);
                        $selectedFull = url('/storage/app/private/albums/' . $album->id . '/' . $filename);
                    }
                }
            }

            $album->selected_thumbnail_url = $selectedThumb;
            $album->selected_image_url = $selectedFull;
        }
    }
}
