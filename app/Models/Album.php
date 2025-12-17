<?php

namespace App\Models;

use App\Observers\AlbumObserver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Crypt;
use App\Services\ImageService;

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
    ];

    /**
     * Decrypt and return images as an indexed array.
     * Supports rows that are still plain JSON (not yet encrypted) as a fallback.
     */
    public function getImagesAttribute($value)
    {
        if (empty($value)) {
            return [];
        }

        // Try to decrypt the stored value. If it fails, assume it's plain JSON or a string path.
        try {
            $decrypted = Crypt::decryptString($value);
            $json = $decrypted;
        } catch (\Throwable $e) {
            $json = $value;
        }

        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            return array_values($decoded);
        }

        // Fallback to single string
        return [$json];
    }

    /**
     * Normalize and encrypt images before saving to DB.
     * Accepts UUID=>path maps (from Filament), indexed arrays, or JSON strings.
     * Stores images as plain paths without ID wrapper.
     */
    public function setImagesAttribute($value)
    {
        $arr = [];

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $arr = is_array($decoded) ? $decoded : [$value];
        } elseif (is_array($value)) {
            $arr = $value;
        }

        // Drop any client-side UUID keys and keep plain indexed array of paths
        $arr = array_values($arr);

        $json = json_encode($arr);

        // Encrypt JSON for storage
        $this->attributes['images'] = Crypt::encryptString($json);
    }

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

        // Use the first image (not a random one) for a stable preview
        $firstPath = reset($images);
        $filename = basename($firstPath);

        $disk = config('filesystems.default');
        $uploadFolder = $disk === 's3' ? config('filesystems.disks.s3.upload_folder', 'sd_develop') : '';

        // If this disk is configured to keep images encrypted at rest, route both
        // thumbnail and full image through our decrypting controller so the app
        // can decrypt on-the-fly. This covers 's3' and any other disk listed in
        // image_encrypt.encrypted_disks (for example 'public').
        if (ImageService::isEncryptedDisk($disk)) {
            $this->selected_thumbnail_url = url("/albums/thumbnail/{$this->id}/{$filename}");
            $this->selected_image_url = url("/albums/image/{$this->id}/{$filename}");
        } else if ($disk === 's3') {
            // Unencrypted S3: point thumbnails to the CDN for speed and full image to controller
            $bucket = config('filesystems.disks.s3.bucket');
            $region = config('filesystems.disks.s3.region');
            $cdnUrl = "https://{$bucket}.{$region}.cdn.digitaloceanspaces.com";
            $this->selected_thumbnail_url = "{$cdnUrl}/{$uploadFolder}/albums/{$this->id}/thumbnails/{$filename}";
            $this->selected_image_url = url("/albums/image/{$this->id}/{$filename}");
        } else {
            // Local disk: if the disk exposes a URL, use it; otherwise build a storage URL.
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
     * Decrypt and return as array. Handles encrypted/plain JSON or single values.
     * Similar to getImagesAttribute but for metadata fields.
     */
    public function decryptArray($value)
    {
        if (empty($value)) {
            return [];
        }

        try {
            $decrypted = Crypt::decryptString($value);
            $json = $decrypted;
        } catch (\Throwable $e) {
            $json = $value;
        }

        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            $result = array_values($decoded);
            // Unwrap any quoted strings in the array
            return array_map(function($item) {
                if (is_string($item) && (substr($item, 0, 1) === '"' && substr($item, -1) === '"')) {
                    // Try to decode as JSON string
                    $unquoted = json_decode($item, true);
                    return $unquoted !== null ? $unquoted : $item;
                }
                return $item;
            }, $result);
        }

        // If it decoded to a non-array (string/number), wrap it in an array for consistency
        if ($decoded !== null && !is_array($decoded)) {
            return [$decoded];
        }

        // Return raw JSON wrapped in array if decode failed (plain text)
        return [$json];
    }

    /**
     * Encrypt array as JSON. Accepts arrays or JSON strings.
     * Similar to setImagesAttribute but for metadata fields.
     */
    public function encryptArray($value)
    {
        $arr = [];

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $arr = is_array($decoded) ? $decoded : [$value];
        } elseif (is_array($value)) {
            $arr = $value;
        }

        $arr = array_values($arr);
        $json = json_encode($arr);

        return Crypt::encryptString($json);
    }


    // Mutators / Accessors for metadata array fields (encrypted arrays like images)
    public function setPositiveAttribute($value)
    {
        $this->attributes['positive'] = $this->encryptArray($value);
    }

    public function getPositiveAttribute($value)
    {
        return $this->normalizeMetadataKeys($this->decryptArray($value));
    }

    public function setNegativeAttribute($value)
    {
        $this->attributes['negative'] = $this->encryptArray($value);
    }

    public function getNegativeAttribute($value)
    {
        return $this->normalizeMetadataKeys($this->decryptArray($value));
    }

    public function setMetadataAttribute($value)
    {
        $this->attributes['metadata'] = $this->encryptArray($value);
    }

    public function getMetadataAttribute($value)
    {
        return $this->normalizeMetadataKeys($this->decryptArray($value));
    }

    public function setCommentAttribute($value)
    {
        if (is_array($value)) {
            // If array is passed (from old structure), take first element
            $value = $value[0] ?? '';
        }
        $this->attributes['comment'] = Crypt::encryptString((string) $value);
    }

    public function getCommentAttribute($value)
    {
        if (empty($value)) {
            return '';
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable $e) {
            return $value;
        }
    }

    public function setLorasAttribute($value)
    {
        $this->attributes['loras'] = $this->encryptArray($value);
    }

    public function getLorasAttribute($value)
    {
        return $this->normalizeMetadataKeys($this->decryptArray($value));
    }

    // Numeric or array fields saved as longText and encrypted
    public function setSeedAttribute($value)
    {
        $this->attributes['seed'] = $this->encryptArray($value);
    }

    public function getSeedAttribute($value)
    {
        return $this->normalizeMetadataKeys($this->decryptArray($value));
    }

    public function setStepsAttribute($value)
    {
        $this->attributes['steps'] = $this->encryptArray($value);
    }

    public function getStepsAttribute($value)
    {
        return $this->normalizeMetadataKeys($this->decryptArray($value));
    }

    public function setCfgAttribute($value)
    {
        $this->attributes['cfg'] = $this->encryptArray($value);
    }

    public function getCfgAttribute($value)
    {
        return $this->normalizeMetadataKeys($this->decryptArray($value));
    }

    public function setSamplerNameAttribute($value)
    {
        $this->attributes['sampler_name'] = $this->encryptArray($value);
    }

    public function getSamplerNameAttribute($value)
    {
        return $this->normalizeMetadataKeys($this->decryptArray($value));
    }

    public function setSchedulerAttribute($value)
    {
        $this->attributes['scheduler'] = $this->encryptArray($value);
    }

    public function getSchedulerAttribute($value)
    {
        return $this->normalizeMetadataKeys($this->decryptArray($value));
    }

    public function setDenoiseAttribute($value)
    {
        $this->attributes['denoise'] = $this->encryptArray($value);
    }

    public function getDenoiseAttribute($value)
    {
        return $this->normalizeMetadataKeys($this->decryptArray($value));
    }

    public function setCkptNameAttribute($value)
    {
        $this->attributes['ckpt_name'] = $this->encryptArray($value);
    }

    public function getCkptNameAttribute($value)
    {
        return $this->normalizeMetadataKeys($this->decryptArray($value));
    }

    public function setWidthAttribute($value)
    {
        $this->attributes['width'] = $this->encryptArray($value);
    }

    public function getWidthAttribute($value)
    {
        return $this->normalizeMetadataKeys($this->decryptArray($value));
    }

    public function setHeightAttribute($value)
    {
        $this->attributes['height'] = $this->encryptArray($value);
    }

    public function getHeightAttribute($value)
    {
        return $this->normalizeMetadataKeys($this->decryptArray($value));
    }

    /**
     * Normalize legacy metadata item keys. If an item uses 'id' as the image key
     * convert it to 'img'. Works for arrays of items and returns non-arrays untouched.
     *
     * @param mixed $items
     * @return mixed
     */
    private function normalizeMetadataKeys($items)
    {
        if (!is_array($items)) {
            return $items;
        }

        return array_values(array_map(function ($item) {
            if (is_array($item)) {
                // Convert top-level 'id' => 'img' for compatibility
                if (array_key_exists('id', $item) && !array_key_exists('img', $item)) {
                    $item['img'] = $item['id'];
                    unset($item['id']);
                }

                // Also handle nested arrays where individual elements may be id=>value pairs
                foreach ($item as $k => $v) {
                    if (is_array($v)) {
                        // If nested array has 'id' keys, convert them as well
                        if (array_key_exists('id', $v) && !array_key_exists('img', $v)) {
                            $v['img'] = $v['id'];
                            unset($v['id']);
                            $item[$k] = $v;
                        }
                    }
                }
            }
            return $item;
        }, $items));
    }

    /**
     * Return the index of the image that matches the given filename (basename).
     * Returns null if not found.
     *
     * @param string $filename
     * @return int|null
     */
    public function indexForFilename(string $filename)
    {
        $images = $this->images ?? [];
        foreach ($images as $i => $path) {
            if (basename($path) === $filename) {
                return $i;
            }
        }
        return null;
    }

    /**
     * Return a metadata value for a given field at the provided index.
     * Handles items that may be arrays like ['value' => '...'] or scalar values.
     *
     * @param string $field
     * @param int $index
     * @return mixed|null
     */
    public function metadataAt(string $field, int $index)
    {
        if (!property_exists($this, $field) && !isset($this->{$field})) {
            // Try accessing via attribute getters (e.g., ckpt_name uses getCkptNameAttribute)
            $arr = $this->{$field} ?? [];
        } else {
            $arr = $this->{$field} ?? [];
        }

        if (!is_array($arr) || !array_key_exists($index, $arr)) {
            return null;
        }

        $val = $arr[$index];
        if (is_array($val) && isset($val['value'])) {
            return $val['value'];
        }
        return $val;
    }
}
