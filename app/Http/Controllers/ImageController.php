<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use App\Services\ImageService;

class ImageController extends Controller
{
    /**
     * Stream a decrypted image stored on S3.
     *
     * Note: thumbnails remain public on the bucket for fast display. Full images
     * are stored encrypted and must be routed through this controller.
     */
    public function showFromS3($albumId, $filename)
    {
        $disk = config('filesystems.default');

        // Build the path depending on disk type. For S3 the upload_folder prefix may be used.
        if ($disk === 's3') {
            $uploadFolder = config('filesystems.disks.s3.upload_folder', 'sd_develop');
            $path = "{$uploadFolder}/albums/{$albumId}/{$filename}";
        } else {
            $path = "albums/{$albumId}/{$filename}";
        }

        if (!Storage::disk($disk)->exists($path)) {
            abort(404);
        }

        $contents = Storage::disk($disk)->get($path);

        $decrypted = $contents;
        if (ImageService::isEncryptedDisk($disk)) {
            try {
                $decrypted = base64_decode(Crypt::decryptString($contents));
            } catch (\Exception $e) {
                abort(404);
            }
        }

        $finfo = finfo_open();
        $mime = finfo_buffer($finfo, $decrypted, FILEINFO_MIME_TYPE);
        finfo_close($finfo);

        return response($decrypted, 200)
            ->header('Content-Type', $mime)
            ->header('Content-Disposition', 'inline; filename="' . $filename . '"');
    }
}
