<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

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
        if ($disk !== 's3') {
            abort(404);
        }

        $uploadFolder = config('filesystems.disks.s3.upload_folder', 'sd_develop');
        $path = "{$uploadFolder}/albums/{$albumId}/{$filename}";

        if (!Storage::disk($disk)->exists($path)) {
            abort(404);
        }

        $encrypted = Storage::disk($disk)->get($path);

        try {
            $decrypted = base64_decode(Crypt::decryptString($encrypted));
        } catch (\Exception $e) {
            abort(404);
        }

        $finfo = finfo_open();
        $mime = finfo_buffer($finfo, $decrypted, FILEINFO_MIME_TYPE);
        finfo_close($finfo);

        return response($decrypted, 200)
            ->header('Content-Type', $mime)
            ->header('Content-Disposition', 'inline; filename="' . $filename . '"');
    }
}
