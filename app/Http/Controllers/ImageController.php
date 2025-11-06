<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Services\ImageService;

class ImageController extends Controller
{
    /**
     * Stream a decrypted image stored on S3.
     *
     * Note: thumbnails remain public on the bucket for fast display. Full images
     * are stored encrypted and must be routed through this controller.
     */
    public function showImage($albumId, $filename)
    {
        Log::info('showImage request', ['album_id' => $albumId, 'filename' => $filename]);
        
        $disk = config('filesystems.default');

        // Build the path depending on disk type. For S3 the upload_folder prefix may be used.
        if ($disk === 's3') {
            $uploadFolder = config('filesystems.disks.s3.upload_folder', 'sd_develop');
            $path = "{$uploadFolder}/albums/{$albumId}/{$filename}";
        } else {
            $path = "albums/{$albumId}/{$filename}";
        }

        Log::debug('Image path', ['album_id' => $albumId, 'disk' => $disk, 'path' => $path]);

        if (!Storage::disk($disk)->exists($path)) {
            Log::warn('Image not found', ['album_id' => $albumId, 'disk' => $disk, 'filename' => $filename, 'path' => $path]);
            abort(404);
        }

        Log::debug('Image found, reading contents', ['album_id' => $albumId, 'disk' => $disk, 'filename' => $filename]);
        $contents = Storage::disk($disk)->get($path);

        $decrypted = $contents;
        if (ImageService::isEncryptedDisk($disk)) {
            Log::debug('Disk is encrypted, attempting to decrypt', ['album_id' => $albumId, 'disk' => $disk, 'filename' => $filename]);
            try {
                $decrypted = base64_decode(Crypt::decryptString($contents));
                Log::debug('Image decrypted successfully', ['album_id' => $albumId, 'disk' => $disk, 'filename' => $filename]);
            } catch (\Exception $e) {
                Log::error('Decryption failed for image', ['album_id' => $albumId, 'disk' => $disk, 'filename' => $filename, 'error' => $e->getMessage()]);
                abort(404);
            }
        }

        $finfo = finfo_open();
        $mime = finfo_buffer($finfo, $decrypted, FILEINFO_MIME_TYPE);
        finfo_close($finfo);

        Log::info('Streaming image', ['album_id' => $albumId, 'disk' => $disk, 'filename' => $filename, 'mime_type' => $mime]);

        return response($decrypted, 200)
            ->header('Content-Type', $mime)
            ->header('Content-Disposition', 'inline; filename="' . $filename . '"')
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    /**
     * Stream a decrypted thumbnail from storage (for encrypted disks).
     */
    public function showThumbnail($albumId, $filename)
    {
        Log::info('showThumbnail request', ['album_id' => $albumId, 'filename' => $filename]);
        
        $disk = config('filesystems.default');

        if ($disk === 's3') {
            $uploadFolder = config('filesystems.disks.s3.upload_folder', 'sd_develop');
            $path = "{$uploadFolder}/albums/{$albumId}/thumbnails/{$filename}";
        } else {
            $path = "albums/{$albumId}/thumbnails/{$filename}";
        }

        Log::debug('Thumbnail path', ['album_id' => $albumId, 'disk' => $disk, 'path' => $path]);

        if (!Storage::disk($disk)->exists($path)) {
            Log::warn('Thumbnail not found', ['album_id' => $albumId, 'disk' => $disk, 'filename' => $filename, 'path' => $path]);
            abort(404);
        }

        Log::debug('Thumbnail found, reading contents', ['album_id' => $albumId, 'disk' => $disk, 'filename' => $filename]);
        $contents = Storage::disk($disk)->get($path);

        $decrypted = $contents;
        if (ImageService::isEncryptedDisk($disk)) {
            Log::debug('Disk is encrypted, attempting to decrypt', ['album_id' => $albumId, 'disk' => $disk, 'filename' => $filename]);
            try {
                $decrypted = base64_decode(Crypt::decryptString($contents));
                Log::debug('Thumbnail decrypted successfully', ['album_id' => $albumId, 'disk' => $disk, 'filename' => $filename]);
            } catch (\Exception $e) {
                Log::error('Decryption failed for thumbnail', ['album_id' => $albumId, 'disk' => $disk, 'filename' => $filename, 'error' => $e->getMessage()]);
                abort(404);
            }
        }

        $finfo = finfo_open();
        $mime = finfo_buffer($finfo, $decrypted, FILEINFO_MIME_TYPE);
        finfo_close($finfo);

        Log::info('Streaming thumbnail', ['album_id' => $albumId, 'disk' => $disk, 'filename' => $filename, 'mime_type' => $mime]);

        return response($decrypted, 200)
            ->header('Content-Type', $mime)
            ->header('Content-Disposition', 'inline; filename="' . $filename . '"')
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }
}
