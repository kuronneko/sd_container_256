<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\MetaDataService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Crypt;
use App\Services\ImageService;

class TestMetadata extends Command
{
    protected $signature = 'test:metadata {album_id=16} {filename=01K9DQSHXNMN2VYJ8MK8BG860H.png}';
    protected $description = 'Test metadata extraction from stored images';

    public function handle()
    {
        $album_id = $this->argument('album_id');
        $filename = $this->argument('filename');

        $this->info("=== Testing Metadata Extraction ===");
        $this->info("Album ID: $album_id");
        $this->info("Filename: $filename\n");

        $disk = config('filesystems.default');
        $image_path = 'albums/' . $album_id . '/' . $filename;

        if ($disk === 's3') {
            $upload_folder = config('filesystems.disks.s3.upload_folder', 'sd_develop');
            $full_path = $upload_folder . '/' . $image_path;
        } else {
            $full_path = $image_path;
        }

        $this->line("Looking for: $full_path");
        $this->line("Disk: $disk\n");

        try {
            if (!Storage::disk($disk)->exists($full_path)) {
                $this->error("✗ File not found at path: $full_path");
                return 1;
            }

            $this->info("✓ File exists in storage");

            $encrypted_content = Storage::disk($disk)->get($full_path);
            $this->info("✓ Retrieved encrypted content: " . strlen($encrypted_content) . " bytes");

            // Try to decrypt
            try {
                $decrypted = Crypt::decryptString($encrypted_content);
                $this->info("✓ Successfully decrypted: " . strlen($decrypted) . " bytes\n");

                // Verify PNG signature
                $png_sig = substr($decrypted, 0, 8);
                $expected_sig = "\x89PNG\r\n\x1a\n";

                if ($png_sig === $expected_sig) {
                    $this->info("✓ Valid PNG signature detected");
                } else {
                    $this->error("✗ Invalid PNG signature!");
                    $this->line("  Expected: " . bin2hex($expected_sig));
                    $this->line("  Got: " . bin2hex($png_sig) . "\n");
                    return 1;
                }

                // Extract metadata
                $this->line("\nExtracting metadata...");
                $metadata = MetaDataService::extractMetadataFromContent($decrypted);

                if ($metadata) {
                    $this->info("✓ METADATA FOUND!");
                    $this->line(json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                    return 0;
                } else {
                    $this->warn("✗ NO METADATA FOUND\n");
                    $this->line("This means the original image doesn't have embedded metadata.");
                    $this->line("Possible reasons:");
                    $this->line("  1. Image was not created by ComfyUI");
                    $this->line("  2. ComfyUI metadata embedding was disabled");
                    $this->line("  3. Metadata was stripped before upload\n");
                    return 1;
                }

            } catch (\Exception $decrypt_error) {
                $this->error("✗ Decryption failed: " . $decrypt_error->getMessage());
                $this->line("This suggests encryption key mismatch!");
                return 1;
            }

        } catch (\Exception $e) {
            $this->error("✗ Error: " . $e->getMessage());
            return 1;
        }
    }
}
