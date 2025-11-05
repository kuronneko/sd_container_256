<?php

namespace App\Models;

use App\Observers\AlbumObserver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Crypt;

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
        $images = $this->images ?? [];
        $this->count_images = count($images);

        $this->selected_thumbnail_url = null;
        $this->selected_image_url = null;

        if ($this->count_images === 0) {
            return;
        }

        $randKey = array_rand($images);
        $filename = basename($images[$randKey]);

        if (config('filesystems.default') === 's3') {
            $uploadFolder = config('filesystems.disks.s3.upload_folder', 'sd_develop');
            $bucket = config('filesystems.disks.s3.bucket');
            $region = config('filesystems.disks.s3.region');
            $cdnUrl = "https://{$bucket}.{$region}.cdn.digitaloceanspaces.com";

            $this->selected_thumbnail_url = "{$cdnUrl}/{$uploadFolder}/albums/{$this->id}/thumbnails/{$filename}";
            $this->selected_image_url = "{$cdnUrl}/{$uploadFolder}/albums/{$this->id}/{$filename}";
        } else {
            $disk = config('filesystems.default');
            $diskUrl = config("filesystems.disks.{$disk}.url");
            if ($diskUrl) {
                $this->selected_thumbnail_url = rtrim($diskUrl, '/') . "/albums/{$this->id}/thumbnails/{$filename}";
                $this->selected_image_url = rtrim($diskUrl, '/') . "/albums/{$this->id}/{$filename}";
            } else {
                $this->selected_thumbnail_url = url('/storage/app/private/albums/' . $this->id . '/thumbnails/' . $filename);
                $this->selected_image_url = url('/storage/app/private/albums/' . $this->id . '/' . $filename);
            }
        }
    }

    /**
     * Decrypt a value if possible. Returns original value if decryption fails.
     */
    public static function decryptValue($value)
    {
        if (is_null($value) || $value === '') {
            return $value;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Exception $e) {
            return $value;
        }
    }

    /**
     * Encrypt a value if it isn't already encrypted.
     */
    public static function encryptValue($value)
    {
        if (is_null($value) || $value === '') {
            return $value;
        }

        try {
            // If this succeeds, it's already encrypted
            Crypt::decryptString($value);
            return $value;
        } catch (\Exception $e) {
            return Crypt::encryptString((string) $value);
        }
    }

    // Mutators / Accessors for encrypted fields
    public function setPositiveAttribute($value)
    {
        $this->attributes['positive'] = self::encryptValue($value);
    }

    public function getPositiveAttribute($value)
    {
        return self::decryptValue($value);
    }

    public function setNegativeAttribute($value)
    {
        $this->attributes['negative'] = self::encryptValue($value);
    }

    public function getNegativeAttribute($value)
    {
        return self::decryptValue($value);
    }

    public function setMetadataAttribute($value)
    {
        $this->attributes['metadata'] = self::encryptValue($value);
    }

    public function getMetadataAttribute($value)
    {
        return self::decryptValue($value);
    }

    public function setCommentAttribute($value)
    {
        $this->attributes['comment'] = self::encryptValue($value);
    }

    public function getCommentAttribute($value)
    {
        return self::decryptValue($value);
    }

    public function setLorasAttribute($value)
    {
        $this->attributes['loras'] = self::encryptValue($value);
    }

    public function getLorasAttribute($value)
    {
        return self::decryptValue($value);
    }

    // Numeric or short fields saved as longText and encrypted
    public function setSeedAttribute($value)
    {
        $this->attributes['seed'] = self::encryptValue($value);
    }

    public function getSeedAttribute($value)
    {
        return self::decryptValue($value);
    }

    public function setStepsAttribute($value)
    {
        $this->attributes['steps'] = self::encryptValue($value);
    }

    public function getStepsAttribute($value)
    {
        return self::decryptValue($value);
    }

    public function setCfgAttribute($value)
    {
        $this->attributes['cfg'] = self::encryptValue($value);
    }

    public function getCfgAttribute($value)
    {
        return self::decryptValue($value);
    }

    public function setSamplerNameAttribute($value)
    {
        $this->attributes['sampler_name'] = self::encryptValue($value);
    }

    public function getSamplerNameAttribute($value)
    {
        return self::decryptValue($value);
    }

    public function setSchedulerAttribute($value)
    {
        $this->attributes['scheduler'] = self::encryptValue($value);
    }

    public function getSchedulerAttribute($value)
    {
        return self::decryptValue($value);
    }

    public function setDenoiseAttribute($value)
    {
        $this->attributes['denoise'] = self::encryptValue($value);
    }

    public function getDenoiseAttribute($value)
    {
        return self::decryptValue($value);
    }

    public function setCkptNameAttribute($value)
    {
        $this->attributes['ckpt_name'] = self::encryptValue($value);
    }

    public function getCkptNameAttribute($value)
    {
        return self::decryptValue($value);
    }

    public function setWidthAttribute($value)
    {
        $this->attributes['width'] = self::encryptValue($value);
    }

    public function getWidthAttribute($value)
    {
        return self::decryptValue($value);
    }

    public function setHeightAttribute($value)
    {
        $this->attributes['height'] = self::encryptValue($value);
    }

    public function getHeightAttribute($value)
    {
        return self::decryptValue($value);
    }
}
