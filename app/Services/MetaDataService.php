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
     * Metadata is extracted during upload and stored in session
     */
    public static function extractAndSaveMetadata(Album $album): void
    {
        Log::info('extractAndSaveMetadata started', ['album_id' => $album->id, 'image_count' => count($album->images ?? [])]);

        $images = $album->images ?? [];

        if (empty($images)) {
            Log::info('No images to process, skipping metadata extraction', ['album_id' => $album->id]);
            return;
        }

        // Check session for metadata from each image (extracted during upload)
        foreach ($images as $imageIndex => $image) {
            $fileName = basename($image);
            $sessionKey = "image_metadata_{$fileName}";
            $comfyuiData = session()->get($sessionKey);

            if ($comfyuiData) {
                // Use filename as the metadata ID
                $metadataId = $fileName;

                Log::info('Found metadata in session for image', ['album_id' => $album->id, 'file_name' => $fileName, 'image_index' => $imageIndex, 'metadata_id' => $metadataId]);
                self::saveComfyUIMetadata($album, $comfyuiData, $metadataId);
                session()->forget($sessionKey);
                Log::info('Metadata saved to album', ['album_id' => $album->id, 'file_name' => $fileName, 'image_index' => $imageIndex, 'metadata_id' => $metadataId]);
            }
        }

        Log::info('extractAndSaveMetadata completed', ['album_id' => $album->id]);
    }

    /**
     * Update metadata from images for existing album
     * Metadata is extracted during upload and stored in session
     *
     * @param Album $album
     * @param bool $forceRefresh Force re-extraction of metadata from storage if not in session
     */
    public static function updateMetadataFromImages(Album $album, bool $forceRefresh = false): void
    {
        Log::info('updateMetadataFromImages started', ['album_id' => $album->id, 'image_count' => count($album->images ?? []), 'force_refresh' => $forceRefresh]);

        $images = $album->images ?? [];

        if (empty($images)) {
            Log::info('No images found, skipping metadata update', ['album_id' => $album->id]);
            return;
        }

        $disk = config('filesystems.default');

        // We'll rebuild metadata and all parsed arrays in a single pass and overwrite the album's stored values.
        $rebuiltMetadata = [];

        // Local accumulators for prompt-derived arrays
        $seedArray = [];
        $stepsArray = [];
        $cfgArray = [];
        $samplerArray = [];
        $schedulerArray = [];
        $denoiseArray = [];
        $widthArray = [];
        $heightArray = [];
        $ckptArray = [];
        $positiveArray = [];
        $negativeArray = [];
        $lorasArray = [];

        // Helper that processes a single comfyuiData and accumulates into local arrays
        $processComfy = function (?array $comfyuiData, string $metadataId) use (&$seedArray, &$stepsArray, &$cfgArray, &$samplerArray, &$schedulerArray, &$denoiseArray, &$widthArray, &$heightArray, &$ckptArray, &$positiveArray, &$negativeArray, &$lorasArray) {
            if (empty($comfyuiData) || !isset($comfyuiData['prompt']) || !is_array($comfyuiData['prompt'])) {
                return;
            }

            $promptData = $comfyuiData['prompt'];

            // First pass to find KSampler and positive/negative nodes
            $positiveNodeId = null;
            $negativeNodeId = null;
            $loraNames = [];
            $dimensionsFound = false;

            foreach ($promptData as $nodeId => $nodeData) {
                if (isset($nodeData['class_type']) && $nodeData['class_type'] === 'KSampler') {
                    $inputs = $nodeData['inputs'] ?? [];

                    if (isset($inputs['seed'])) {
                        $seedArray[] = ['img' => $metadataId, 'value' => $inputs['seed']];
                    }
                    if (isset($inputs['steps'])) {
                        $stepsArray[] = ['img' => $metadataId, 'value' => $inputs['steps']];
                    }
                    if (isset($inputs['cfg'])) {
                        $cfgArray[] = ['img' => $metadataId, 'value' => $inputs['cfg']];
                    }
                    if (isset($inputs['sampler_name'])) {
                        $samplerArray[] = ['img' => $metadataId, 'value' => $inputs['sampler_name']];
                    }
                    if (isset($inputs['scheduler'])) {
                        $schedulerArray[] = ['img' => $metadataId, 'value' => $inputs['scheduler']];
                    }
                    if (isset($inputs['denoise'])) {
                        $denoiseArray[] = ['img' => $metadataId, 'value' => $inputs['denoise']];
                    }

                    if (isset($inputs['positive']) && is_array($inputs['positive']) && count($inputs['positive']) > 0) {
                        $positiveNodeId = (string) $inputs['positive'][0];
                    }
                    if (isset($inputs['negative']) && is_array($inputs['negative']) && count($inputs['negative']) > 0) {
                        $negativeNodeId = (string) $inputs['negative'][0];
                    }

                    break;
                }
            }

            // Second pass: extract other nodes
            foreach ($promptData as $nodeId => $nodeData) {
                if (!isset($nodeData['class_type']) || !isset($nodeData['inputs'])) {
                    continue;
                }

                $inputs = $nodeData['inputs'];
                $classType = $nodeData['class_type'];

                switch ($classType) {
                    case 'CheckpointLoaderSimple':
                        if (isset($inputs['ckpt_name'])) {
                            $ckptArray[] = ['img' => $metadataId, 'value' => $inputs['ckpt_name']];
                        }
                        break;

                    case 'LoraLoader':
                        if (isset($inputs['lora_name'])) {
                            $name = $inputs['lora_name'];
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

                            $loraNames[] = ['img' => $metadataId, 'value' => $name];
                        }
                        break;

                    case 'EmptyLatentImage':
                    case 'EmptySD3LatentImage':
                        if (!$dimensionsFound) {
                            if (isset($inputs['width'])) {
                                $widthArray[] = ['img' => $metadataId, 'value' => $inputs['width']];
                            }
                            if (isset($inputs['height'])) {
                                $heightArray[] = ['img' => $metadataId, 'value' => $inputs['height']];
                            }
                            $dimensionsFound = true;
                        }
                        break;

                    case 'CLIPTextEncode':
                        if (isset($inputs['text'])) {
                            $text = $inputs['text'];
                            $currentNodeId = (string) $nodeId;

                            if ($currentNodeId === $positiveNodeId) {
                                $positiveArray[] = ['img' => $metadataId, 'value' => $text];
                            } elseif ($currentNodeId === $negativeNodeId) {
                                $negativeArray[] = ['img' => $metadataId, 'value' => $text];
                            }
                        }
                        break;
                }
            }

            if (!empty($loraNames)) {
                foreach ($loraNames as $loraName) {
                    $lorasArray[] = $loraName;
                }
            }
        };

        // Iterate images and collect metadata (session first)
        foreach ($images as $imageIndex => $image) {
            $fileName = basename($image);
            $sessionKey = "image_metadata_{$fileName}";
            $comfyuiData = session()->get($sessionKey);

            if ($comfyuiData) {
                $metadataId = $fileName;
                $rebuiltMetadata[] = [
                    'img' => $metadataId,
                    'prompt' => $comfyuiData['prompt'] ?? null,
                    'workflow' => $comfyuiData['workflow'] ?? null,
                ];

                // accumulate parsed fields
                $processComfy($comfyuiData, $metadataId);

                // clear session entry
                session()->forget($sessionKey);
                continue;
            }

            // If forceRefresh requested, try extracting from storage
            if ($forceRefresh) {
                try {
                    $normalizedPath = self::getNormalizedImagePath($image);
                    $encryptedContent = Storage::disk($disk)->get($normalizedPath);

                    if (ImageService::isEncryptedDisk($disk)) {
                        try {
                            $contents = Crypt::decryptString($encryptedContent);
                        } catch (\Exception $decryptError) {
                            Log::warning('Failed to decrypt image during metadata rebuild', ['album_id' => $album->id, 'file_name' => $fileName, 'error' => $decryptError->getMessage()]);
                            continue;
                        }
                    } else {
                        $contents = $encryptedContent;
                    }

                    $comfyuiData = self::extractMetadataFromContent($contents);
                    if ($comfyuiData) {
                        $metadataId = $fileName;
                        $rebuiltMetadata[] = [
                            'img' => $metadataId,
                            'prompt' => $comfyuiData['prompt'] ?? null,
                            'workflow' => $comfyuiData['workflow'] ?? null,
                        ];

                        $processComfy($comfyuiData, $metadataId);
                    }
                } catch (\Exception $e) {
                    Log::warning('Error extracting metadata during rebuild', ['album_id' => $album->id, 'file_name' => $fileName, 'error' => $e->getMessage()]);
                    continue;
                }
            }
        }

        // If we collected any metadata items, overwrite album metadata and parsed arrays
        if (!empty($rebuiltMetadata)) {
            $album->metadata = $rebuiltMetadata;

            // Overwrite parsed arrays with rebuilt values
            $album->seed = $seedArray;
            $album->steps = $stepsArray;
            $album->cfg = $cfgArray;
            $album->sampler_name = $samplerArray;
            $album->scheduler = $schedulerArray;
            $album->denoise = $denoiseArray;
            $album->width = $widthArray;
            $album->height = $heightArray;
            $album->ckpt_name = $ckptArray;
            $album->positive = $positiveArray;
            $album->negative = $negativeArray;
            $album->loras = $lorasArray;

            $album->save();
            Log::info('Rebuilt and overwrote album metadata from images', ['album_id' => $album->id, 'items' => count($rebuiltMetadata)]);
        } else {
            Log::info('updateMetadataFromImages completed (no metadata found to rebuild)', ['album_id' => $album->id]);
        }
    }

    /**
     * Normalize image path for storage access
     * Handles S3 upload folder prefix
     */
    protected static function getNormalizedImagePath(string $imagePath): string
    {
        $disk = config('filesystems.default');

        if ($disk === 's3') {
            $uploadFolder = config('filesystems.disks.s3.upload_folder', 'sd_develop');
            // If path doesn't have the upload folder prefix, add it
            if (strpos($imagePath, $uploadFolder . '/') !== 0) {
                return "{$uploadFolder}/{$imagePath}";
            }
        }

        return $imagePath;
    }

    /**
     * Extract metadata from plaintext image content (before encryption)
     * This avoids needing to decrypt the image later just to get metadata
     *
     * @param string $imageContent Raw image bytes
     * @return array|null ComfyUI metadata array or null if not found
     */
    public static function extractMetadataFromContent($imageContent)
    {
        try {
            if (empty($imageContent)) {
                return null;
            }

            // Verify PNG signature
            $pngSignature = substr($imageContent, 0, 8);
            $expectedSignature = "\x89PNG\r\n\x1a\n";
            if ($pngSignature !== $expectedSignature) {
                return null;
            }

            // Use an in-memory stream to avoid writing to disk
            $stream = fopen('php://memory', 'r+');
            if ($stream === false) {
                throw new \Exception('Unable to open memory stream for metadata extraction');
            }

            fwrite($stream, $imageContent);
            rewind($stream);

            $comfyuiData = self::extractPNGMetadata($stream);
            fclose($stream);

            return !empty($comfyuiData) ? $comfyuiData : null;

        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Extract PNG text chunks for ComfyUI metadata
     */
    /**
     * Extract PNG text chunks for ComfyUI metadata
     *
     * Accepts either a path (string) or an open stream resource (php://memory, php://temp, etc.).
     *
     * @param string|resource $source
     */
    protected static function extractPNGMetadata($source): array
    {
        $comfyuiData = [];
        $chunkCount = 0;
        $textChunkCount = 0;

        try {
            $openedHere = false;
            if (is_string($source)) {
                $handle = fopen($source, 'rb');
                $openedHere = true;
            } else {
                $handle = $source;
            }

            if (!$handle) return [];

            // Skip PNG signature
            fseek($handle, 8);

            while (!feof($handle)) {
                $lengthData = fread($handle, 4);
                if (strlen($lengthData) < 4) break;

                $chunkLength = unpack('N', $lengthData)[1] ?? 0;
                $chunkType = fread($handle, 4);
                $chunkCount++;

                if ($chunkType === 'tEXt') {
                    $textChunkCount++;
                    $chunkData = fread($handle, $chunkLength);
                    $nullPos = strpos($chunkData, "\0");

                    if ($nullPos !== false) {
                        $keyword = substr($chunkData, 0, $nullPos);
                        $text = substr($chunkData, $nullPos + 1);

                        if ($keyword === 'prompt' || $keyword === 'workflow') {
                            $jsonData = json_decode($text, true);
                            if (json_last_error() === JSON_ERROR_NONE && is_array($jsonData)) {
                                $comfyuiData[$keyword] = $jsonData;
                            }
                        }
                    }
                } else {
                    if ($chunkLength > 0) {
                        fseek($handle, $chunkLength, SEEK_CUR);
                    }
                }

                fseek($handle, 4, SEEK_CUR);

                if ($chunkType === 'IEND') break;
            }

            if ($openedHere) {
                fclose($handle);
            }

        } catch (\Exception $e) {
            // Silent fail
        }

        return $comfyuiData;
    }

    /**
     * Save ComfyUI metadata to album
     * @param Album $album
     * @param array $comfyuiData
     * @param string|null $metadataId The ID to use for linking metadata to images
     */
    protected static function saveComfyUIMetadata(Album $album, array $comfyuiData, ?string $metadataId = null): void
    {
        $disk = config('filesystems.default');

        // Use the provided metadata ID, or generate one if not provided
        if (!$metadataId) {
            $metadataId = uniqid();
        }

        // Store metadata with img at top level
        $metadataValue = [
            'img' => $metadataId,
            'prompt' => $comfyuiData['prompt'] ?? null,
            'workflow' => $comfyuiData['workflow'] ?? null
        ];

        // Append metadata - ensure metadata is always a clean array
        $currentMetadata = $album->metadata ?? [];

        // If metadata is somehow a string, convert it back to array
        if (is_string($currentMetadata)) {
            $decoded = json_decode($currentMetadata, true);
            $currentMetadata = is_array($decoded) ? $decoded : [];
        }

        // Ensure it's an indexed array
        if (!is_array($currentMetadata)) {
            $currentMetadata = [];
        }

        // Clean up: recursively decode and flatten any deeply nested/stringified JSON in the metadata
        $cleanedMetadata = [];

        // Helper: recursively decode JSON strings until no longer a JSON string
        $recursiveDecode = function ($val) {
            while (is_string($val)) {
                $trim = trim($val);
                if (strlen($trim) === 0) {
                    break;
                }

                // Only attempt decode if it looks like JSON array/object
                $first = $trim[0];
                if ($first !== '[' && $first !== '{') {
                    break;
                }

                $decoded = json_decode($val, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    break;
                }

                $val = $decoded;
            }

            return $val;
        };

        foreach ($currentMetadata as $item) {
            $item = $recursiveDecode($item);

            if (is_array($item)) {
                // If it's a numerically indexed array of items, merge
                $isList = array_keys($item) === range(0, count($item) - 1);
                if ($isList) {
                    foreach ($item as $sub) {
                        // ensure objects remain arrays
                        if (is_string($sub)) {
                            $sub = $recursiveDecode($sub);
                        }
                        $cleanedMetadata[] = $sub;
                    }
                    continue;
                }

                // Associative array - likely a single metadata object
                $cleanedMetadata[] = $item;
                continue;
            }

            // scalar value or unknown structure - keep as-is
            $cleanedMetadata[] = $item;
        }

        $currentMetadata = $cleanedMetadata;

        $currentMetadata[] = $metadataValue;
        $album->metadata = $currentMetadata;

        // Populate album fields from prompt data - appending to arrays
        if (isset($comfyuiData['prompt'])) {
            self::parseComfyUIPromptToFields($album, $comfyuiData['prompt'], $metadataId);
        }

        // Single save at the end
        $album->save();
    }

    /**
     * Parse ComfyUI prompt data and append to album array fields
     * Instead of overwriting, new metadata is appended to existing arrays
     * @param Album $album
     * @param array $promptData
     * @param string|null $metadataId The ID to use for linking metadata to images
     */
    protected static function parseComfyUIPromptToFields(Album $album, array $promptData, ?string $metadataId = null): void
    {
        // Use the provided metadata ID, or generate one if not provided
        if (!$metadataId) {
            $metadataId = uniqid();
        }

        // Cache current values to avoid multiple getter calls
        $seedArray = $album->seed ?? [];
        $stepsArray = $album->steps ?? [];
        $cfgArray = $album->cfg ?? [];
        $samplerArray = $album->sampler_name ?? [];
        $schedulerArray = $album->scheduler ?? [];
        $denoiseArray = $album->denoise ?? [];
        $widthArray = $album->width ?? [];
        $heightArray = $album->height ?? [];
        $ckptArray = $album->ckpt_name ?? [];
        $positiveArray = $album->positive ?? [];
        $negativeArray = $album->negative ?? [];
        $lorasArray = $album->loras ?? [];

        // First pass: find KSampler node and get positive/negative references
        $positiveNodeId = null;
        $negativeNodeId = null;
        $loraNames = [];
        $dimensionsFound = false;

        foreach ($promptData as $nodeId => $nodeData) {
            if (isset($nodeData['class_type']) && $nodeData['class_type'] === 'KSampler') {
                $inputs = $nodeData['inputs'];

                // Extract sampling parameters
                if (isset($inputs['seed'])) {
                    $seedArray[] = ['img' => $metadataId, 'value' => $inputs['seed']];
                }
                if (isset($inputs['steps'])) {
                    $stepsArray[] = ['img' => $metadataId, 'value' => $inputs['steps']];
                }
                if (isset($inputs['cfg'])) {
                    $cfgArray[] = ['img' => $metadataId, 'value' => $inputs['cfg']];
                }
                if (isset($inputs['sampler_name'])) {
                    $samplerArray[] = ['img' => $metadataId, 'value' => $inputs['sampler_name']];
                }
                if (isset($inputs['scheduler'])) {
                    $schedulerArray[] = ['img' => $metadataId, 'value' => $inputs['scheduler']];
                }
                if (isset($inputs['denoise'])) {
                    $denoiseArray[] = ['img' => $metadataId, 'value' => $inputs['denoise']];
                }

                // Get positive/negative node references
                if (isset($inputs['positive']) && is_array($inputs['positive']) && count($inputs['positive']) > 0) {
                    $positiveNodeId = (string) $inputs['positive'][0];
                }
                if (isset($inputs['negative']) && is_array($inputs['negative']) && count($inputs['negative']) > 0) {
                    $negativeNodeId = (string) $inputs['negative'][0];
                }

                break; // Only process first KSampler
            }
        }

        // Second pass: extract other data
        foreach ($promptData as $nodeId => $nodeData) {
            if (!isset($nodeData['class_type']) || !isset($nodeData['inputs'])) {
                continue;
            }

            $inputs = $nodeData['inputs'];
            $classType = $nodeData['class_type'];

            switch ($classType) {
                case 'CheckpointLoaderSimple':
                    if (isset($inputs['ckpt_name'])) {
                        $ckptArray[] = ['img' => $metadataId, 'value' => $inputs['ckpt_name']];
                    }
                    break;

                case 'LoraLoader':
                    if (isset($inputs['lora_name'])) {
                        $name = $inputs['lora_name'];
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

                        $loraNames[] = ['img' => $metadataId, 'value' => $name];
                    }
                    break;

                case 'EmptyLatentImage':
                case 'EmptySD3LatentImage':
                    if (!$dimensionsFound) {
                            if (isset($inputs['width'])) {
                                $widthArray[] = ['img' => $metadataId, 'value' => $inputs['width']];
                        }
                            if (isset($inputs['height'])) {
                                $heightArray[] = ['img' => $metadataId, 'value' => $inputs['height']];
                        }
                        $dimensionsFound = true;
                    }
                    break;

                case 'CLIPTextEncode':
                    if (isset($inputs['text'])) {
                        $text = $inputs['text'];
                        $currentNodeId = (string) $nodeId;

                            if ($currentNodeId === $positiveNodeId) {
                                $positiveArray[] = ['img' => $metadataId, 'value' => $text];
                            } elseif ($currentNodeId === $negativeNodeId) {
                                $negativeArray[] = ['img' => $metadataId, 'value' => $text];
                        }
                    }
                    break;
            }
        }

        // Batch assign all arrays at once
        $album->seed = $seedArray;
        $album->steps = $stepsArray;
        $album->cfg = $cfgArray;
        $album->sampler_name = $samplerArray;
        $album->scheduler = $schedulerArray;
        $album->denoise = $denoiseArray;
        $album->width = $widthArray;
        $album->height = $heightArray;
        $album->ckpt_name = $ckptArray;
        $album->positive = $positiveArray;
        $album->negative = $negativeArray;

        // Add LoRAs if found
        if (!empty($loraNames)) {
            foreach ($loraNames as $loraName) {
                $lorasArray[] = $loraName;
            }
            $album->loras = $lorasArray;
        }
    }
}
