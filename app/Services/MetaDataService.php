<?php

namespace App\Services;

use App\Models\Album;
use App\Services\ImageService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Crypt;

class MetaDataService
{
    /**
     * Extract and save metadata from album images
     */
    public static function extractAndSaveMetadata(Album $album): void
    {
        $disk = config('filesystems.default');
        $uploadFolder = $disk === 's3'
            ? config('filesystems.disks.s3.upload_folder', 'sd_develop')
            : '';

        $tempDirectory = $disk === 's3'
            ? "{$uploadFolder}/albums/temp"
            : "albums/temp";

        // Get images from the record
        $images = $album->images ?? [];

        foreach ($images as $image) {
            $fileName = basename($image);
            $tempPath = "{$tempDirectory}/{$fileName}";

            // Try temp folder first, then album folder
            $imagePath = null;
            if (Storage::disk($disk)->exists($tempPath)) {
                $imagePath = $tempPath;
            } else {
                // Try the album folder
                $albumPath = $disk === 's3'
                    ? "{$uploadFolder}/albums/{$album->id}/{$fileName}"
                    : "albums/{$album->id}/{$fileName}";
                if (Storage::disk($disk)->exists($albumPath)) {
                    $imagePath = $albumPath;
                }
            }

            if ($imagePath) {
                $comfyuiData = self::extractComfyUIData($imagePath, $disk);
                if ($comfyuiData) {
                    // Save prompt + workflow in metadata field and populate album fields
                    self::saveComfyUIMetadata($album, $comfyuiData);
                    break; // Use data from first image with ComfyUI data
                }
            }
        }
    }

    /**
     * Update metadata from images for existing album
     */
    public static function updateMetadataFromImages(Album $album): void
    {
        $images = $album->images ?? [];

        if (empty($images)) {
            return;
        }

        // Get the first image to extract metadata from
        $firstImage = $images[0];
        $fileName = basename($firstImage);

        $disk = config('filesystems.default');
        $uploadFolder = $disk === 's3'
            ? config('filesystems.disks.s3.upload_folder', 'sd_develop')
            : '';

        // Try album folder first, then temp folder
        $imagePath = null;
        $albumPath = $disk === 's3'
            ? "{$uploadFolder}/albums/{$album->id}/{$fileName}"
            : "albums/{$album->id}/{$fileName}";

        if (Storage::disk($disk)->exists($albumPath)) {
            $imagePath = $albumPath;
        } else {
            // Try temp folder
            $tempPath = $disk === 's3'
                ? "{$uploadFolder}/albums/temp/{$fileName}"
                : "albums/temp/{$fileName}";
            if (Storage::disk($disk)->exists($tempPath)) {
                $imagePath = $tempPath;
            }
        }

        if ($imagePath) {
            $comfyuiData = self::extractComfyUIData($imagePath, $disk);
            if ($comfyuiData) {
                self::saveComfyUIMetadata($album, $comfyuiData);
            }
        }
    }

    /**
     * Extract ComfyUI data from image file
     */
    protected static function extractComfyUIData(string $imagePath, string $disk): ?array
    {
        try {
            $imageContent = Storage::disk($disk)->get($imagePath);

            // If the image is stored encrypted on configured disks, try to decrypt it first
            if (ImageService::isEncryptedDisk($disk)) {
                try {
                    $decoded = Crypt::decryptString($imageContent);
                    $imageContent = base64_decode($decoded);
                } catch (\Exception $e) {
                    // If decrypt fails, assume plaintext image; continue
                }
            }
            $tempFile = tempnam(sys_get_temp_dir(), 'img_metadata');
            file_put_contents($tempFile, $imageContent);

            $comfyuiData = self::extractPNGMetadata($tempFile);

            unlink($tempFile);

            return !empty($comfyuiData) ? $comfyuiData : null;

        } catch (\Exception $e) {
            Log::error('Error extracting ComfyUI data from ' . $imagePath . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Extract PNG text chunks for ComfyUI metadata
     */
    protected static function extractPNGMetadata(string $tempFile): array
    {
        $comfyuiData = [];

        try {
            // Read PNG file and extract text chunks
            $handle = fopen($tempFile, 'rb');
            if (!$handle) return [];

            // Skip PNG signature
            fseek($handle, 8);

            while (!feof($handle)) {
                $chunkLength = unpack('N', fread($handle, 4))[1] ?? 0;
                $chunkType = fread($handle, 4);

                if ($chunkType === 'tEXt') {
                    $chunkData = fread($handle, $chunkLength);
                    $nullPos = strpos($chunkData, "\0");
                    if ($nullPos !== false) {
                        $keyword = substr($chunkData, 0, $nullPos);
                        $text = substr($chunkData, $nullPos + 1);

                        // Check for ComfyUI metadata keywords
                        if ($keyword === 'prompt' || $keyword === 'workflow') {
                            $jsonData = json_decode($text, true);
                            if (json_last_error() === JSON_ERROR_NONE && is_array($jsonData)) {
                                $comfyuiData[$keyword] = $jsonData;
                            }
                        }
                    }
                } else {
                    // Skip chunk data
                    fseek($handle, $chunkLength, SEEK_CUR);
                }

                // Skip CRC
                fseek($handle, 4, SEEK_CUR);

                if ($chunkType === 'IEND') break;
            }

            fclose($handle);
        } catch (\Exception $e) {
            Log::error('Error reading PNG metadata: ' . $e->getMessage());
        }

        return $comfyuiData;
    }

    /**
     * Save ComfyUI metadata to album
     */
    protected static function saveComfyUIMetadata(Album $album, array $comfyuiData): void
    {
        // Save prompt + workflow in metadata field
        $metadataContent = "--- ComfyUI Metadata ---\n";
        if (isset($comfyuiData['prompt'])) {
            $metadataContent .= "Prompt:\n" . json_encode($comfyuiData['prompt'], JSON_PRETTY_PRINT) . "\n\n";
        }
        if (isset($comfyuiData['workflow'])) {
            $metadataContent .= "Workflow:\n" . json_encode($comfyuiData['workflow'], JSON_PRETTY_PRINT);
        }

        $album->metadata = $metadataContent;

        // Populate album fields from prompt data
        if (isset($comfyuiData['prompt'])) {
            self::parseComfyUIPromptToFields($album, $comfyuiData['prompt']);
        }

        $album->save();
    }

    /**
     * Parse ComfyUI prompt data and populate album fields
     */
    protected static function parseComfyUIPromptToFields(Album $album, array $promptData): void
    {
        // First pass: find KSampler node and get positive/negative references
        $positiveNodeId = null;
        $negativeNodeId = null;
        $loraNames = [];
        $dimensionsFound = false; // Track if we already found dimensions

        foreach ($promptData as $nodeId => $nodeData) {
            if (isset($nodeData['class_type']) && $nodeData['class_type'] === 'KSampler') {
                $inputs = $nodeData['inputs'];

                // Extract sampling parameters
                if (isset($inputs['seed'])) {
                    $album->seed = $inputs['seed'];
                }
                if (isset($inputs['steps'])) {
                    $album->steps = $inputs['steps'];
                }
                if (isset($inputs['cfg'])) {
                    $album->cfg = $inputs['cfg'];
                }
                if (isset($inputs['sampler_name'])) {
                    $album->sampler_name = $inputs['sampler_name'];
                }
                if (isset($inputs['scheduler'])) {
                    $album->scheduler = $inputs['scheduler'];
                }
                if (isset($inputs['denoise'])) {
                    $album->denoise = $inputs['denoise'];
                }

                // Get positive/negative node references
                if (isset($inputs['positive']) && is_array($inputs['positive'])) {
                    $positiveNodeId = $inputs['positive'][0]; // First element is node ID
                }
                if (isset($inputs['negative']) && is_array($inputs['negative'])) {
                    $negativeNodeId = $inputs['negative'][0]; // First element is node ID
                }

                break; // Only process first KSampler found
            }
        }

        // Second pass: extract other data and map prompts correctly
        foreach ($promptData as $nodeId => $nodeData) {
            if (!isset($nodeData['class_type']) || !isset($nodeData['inputs'])) {
                continue;
            }

            $inputs = $nodeData['inputs'];
            $classType = $nodeData['class_type'];

            switch ($classType) {
                case 'CheckpointLoaderSimple':
                    // Extract model/checkpoint name
                    if (isset($inputs['ckpt_name'])) {
                        $album->ckpt_name = $inputs['ckpt_name'];
                    }
                    break;

                case 'LoraLoader':
                    // Extract LoRA names (collect all LoRAs) and include strengths when available
                    if (isset($inputs['lora_name'])) {
                        $name = $inputs['lora_name'];

                        // strength for model and clip may be present
                        $strengthModel = $inputs['strength_model'] ?? null;
                        $strengthClip = $inputs['strength_clip'] ?? null;

                        if ($strengthModel !== null || $strengthClip !== null) {
                            $parts = [];
                            if ($strengthModel !== null) {
                                $parts[] = 'model: ' . $strengthModel;
                            }
                            if ($strengthClip !== null) {
                                $parts[] = 'clip: ' . $strengthClip;
                            }
                            $name = $name . ' (' . implode(', ', $parts) . ')';
                        }

                        $loraNames[] = $name;
                    }
                    break;

                case 'EmptyLatentImage':
                case 'EmptySD3LatentImage':
                    // Extract image dimensions - only from the first dimension node found
                    if (!$dimensionsFound) {
                        if (isset($inputs['width'])) {
                            $album->width = $inputs['width'];
                        }
                        if (isset($inputs['height'])) {
                            $album->height = $inputs['height'];
                        }
                        $dimensionsFound = true; // Don't extract from subsequent nodes
                    }
                    break;

                case 'CLIPTextEncode':
                    // Map prompts using KSampler references
                    if (isset($inputs['text'])) {
                        $text = $inputs['text'];

                        if ($nodeId == $positiveNodeId) {
                            // This is the positive prompt
                            if (empty($album->positive)) {
                                $album->positive = $text;
                            }
                        } elseif ($nodeId == $negativeNodeId) {
                            // This is the negative prompt
                            if (empty($album->negative)) {
                                $album->negative = $text;
                            }
                        }
                    }
                    break;
            }
        }

        // Save LoRA names separated by comma
        if (!empty($loraNames)) {
            $album->loras = implode(', ', $loraNames);
        }
    }
}
