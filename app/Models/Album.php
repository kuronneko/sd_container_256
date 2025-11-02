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

    protected $fillable = ['positive_prompt', 'negative_prompt', 'extra_configuration', 'images'];

    protected $casts = [
        'images' => 'array',
    ];

    public function getRandomImageAttribute()
    {
        $images = $this->images;
        return $images ? $images[array_rand($images)] : null;
    }

    // Get images with full paths for Filament display
    public function getImagesWithFullPathsAttribute()
    {
        $images = $this->images ?? [];

        if (config('filesystems.default') === 's3') {
            $uploadFolder = config('filesystems.disks.s3.upload_folder', 'sd_develop');
            return array_map(function ($image) use ($uploadFolder) {
                // Add back the upload folder prefix if not already present
                if (strpos($image, $uploadFolder) === false) {
                    return "{$uploadFolder}/{$image}";
                }
                return $image;
            }, $images);
        }

        return $images;
    }

    public function getThumbnailUrlsAttribute()
    {
        $images = $this->images;
        $thumbnails = [];

        if (config('filesystems.default') === 's3') {
            $uploadFolder = config('filesystems.disks.s3.upload_folder', 'sd_develop');
            $bucket = config('filesystems.disks.s3.bucket');
            $region = config('filesystems.disks.s3.region');
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
