<?php

namespace App\Models;

use App\Observers\AlbumObserver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

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
            // Local storage
            foreach ($images as $image) {
                $thumbnails[] = "albums/{$this->id}/thumbnails/" . basename($image);
            }
        }

        return $thumbnails;
    }
}
