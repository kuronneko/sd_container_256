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

    protected $fillable = [
        'seed',
        'steps',
        'cfg',
        'sampler_name',
        'scheduler',
        'denoise',

        'positive',
        'negative',

        'ckpt_name',
        'loras',

        'width',
        'height',

        'metadata_prompt',
        'metadata_workflow',

        'comment',
        'images'
    ];

    protected $casts = [
        'images' => 'array',
    ];

    /**
     * Cache for prepared image payload to ensure consistent URLs per request.
     *
     * @var array|null
     */
    protected ?array $preparedImageCache = null;

/*     public function getRandomImageAttribute()
    {
        $images = $this->images;
        return $images ? $images[array_rand($images)] : null;
    } */

    // Get images with full paths for Filament display
/*     public function getImagesWithFullPathsAttribute()
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
    } */

/*     public function getThumbnailUrlsAttribute()
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
    } */

    /**
     * Prepare and attach selected image URLs (thumbnail and full) and image count
     * to this Album instance. This allows views to use $album->selected_thumbnail_url
     * and $album->selected_image_url without additional processing.
     *
     * This method does not persist any changes to the database; it only sets
     * transient properties on the model instance for display purposes.
     *
     * @return void
     */
    public function prepareSelectedImageUrls(): void
    {
        // Prepare and cache payload, then set transient properties for compatibility.
        $this->ensurePreparedImageCache();

        $payload = $this->preparedImageCache ?? ['thumbnail' => null, 'image' => null, 'count' => 0];

        $this->selected_thumbnail_url = $payload['thumbnail'];
        $this->selected_image_url = $payload['image'];
        $this->count_images = $payload['count'];
    }

    /**
     * Ensure the prepared image payload is computed and cached on the model instance.
     * This method is idempotent and will not reorder collections — it only reads
     * the `images` attribute and constructs URLs.
     *
     * @return void
     */
    protected function ensurePreparedImageCache(): void
    {
        if ($this->preparedImageCache !== null) {
            return;
        }

        $images = $this->images ?? [];
        $count = count($images);

        $thumbnail = null;
        $image = null;

        if ($count > 0) {
            $randKey = array_rand($images);
            $filename = basename($images[$randKey]);

            if (config('filesystems.default') === 's3') {
                $uploadFolder = config('filesystems.disks.s3.upload_folder', 'sd_develop');
                $bucket = config('filesystems.disks.s3.bucket');
                $region = config('filesystems.disks.s3.region');
                $cdnUrl = "https://{$bucket}.{$region}.cdn.digitaloceanspaces.com";

                $thumbnail = "{$cdnUrl}/{$uploadFolder}/albums/{$this->id}/thumbnails/{$filename}";
                $image = "{$cdnUrl}/{$uploadFolder}/albums/{$this->id}/{$filename}";
            } else {
                $disk = config('filesystems.default');
                $diskUrl = config("filesystems.disks.{$disk}.url");
                if ($diskUrl) {
                    $thumbnail = rtrim($diskUrl, '/') . "/albums/{$this->id}/thumbnails/{$filename}";
                    $image = rtrim($diskUrl, '/') . "/albums/{$this->id}/{$filename}";
                } else {
                    $thumbnail = url('/storage/app/private/albums/' . $this->id . '/thumbnails/' . $filename);
                    $image = url('/storage/app/private/albums/' . $this->id . '/' . $filename);
                }
            }
        }

        $this->preparedImageCache = [
            'thumbnail' => $thumbnail,
            'image' => $image,
            'count' => $count,
        ];
    }

    /**
     * Lazy accessors for prepared image data. These use the cached payload so
     * multiple accesses within the same request return the same values.
     */
    public function getSelectedThumbnailUrlAttribute()
    {
        $this->ensurePreparedImageCache();
        return $this->preparedImageCache['thumbnail'] ?? null;
    }

    public function getSelectedImageUrlAttribute()
    {
        $this->ensurePreparedImageCache();
        return $this->preparedImageCache['image'] ?? null;
    }

    public function getCountImagesAttribute()
    {
        $this->ensurePreparedImageCache();
        return $this->preparedImageCache['count'] ?? 0;
    }
}
