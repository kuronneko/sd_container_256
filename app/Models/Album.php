<?php

namespace App\Models;

use App\Observers\AlbumObserver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Storage;

class Album extends Model
{
    use HasFactory;

    protected $fillable = ['prompt', 'images'];

    protected $casts = [
        'images' => 'array',
    ];

    public function getRandomImageAttribute()
    {
        $images = $this->images;
        return $images ? $images[array_rand($images)] : null;
    }


    public function getThumbnailUrlsAttribute()
    {
        $images = $this->images;
        $thumbnails = [];

        if (config('filesystems.default') === 's3') {
            $uploadFolder = env('AWS_UPLOAD_FOLDER', 'sd_develop');
            $bucket = env('AWS_BUCKET', 'cbpw');
            $region = env('AWS_DEFAULT_REGION', 'nyc3');
            $cdnUrl = "https://{$bucket}.{$region}.cdn.digitaloceanspaces.com";

            foreach ($images as $image) {
                $path = "{$uploadFolder}/albums/{$this->id}/thumbnails/" . basename($image);
                $thumbnails[] = "{$cdnUrl}/{$path}";
            }
        } else {
            // Local storage - generate URLs
            foreach ($images as $image) {
                $thumbnailPath = "albums/{$this->id}/thumbnails/" . basename($image);
                $disk = config('filesystems.default');
                $diskUrl = config("filesystems.disks.{$disk}.url");
                if ($diskUrl) {
                    $thumbnails[] = $diskUrl . '/' . $thumbnailPath;
                } else {
                    // Fallback if url is not configured
                    $thumbnails[] = url('/storage/app/private/' . $thumbnailPath);
                }
            }
        }

        return $thumbnails;
    }
}
