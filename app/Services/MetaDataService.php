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

        // Check session for metadata from the first image (extracted during upload)
        foreach ($images as $image) {
            $fileName = basename($image);
            $sessionKey = "image_metadata_{$fileName}";
            $comfyuiData = session()->get($sessionKey);

            if ($comfyuiData) {
                Log::info('Found metadata in session for first image', ['album_id' => $album->id, 'file_name' => $fileName]);
                self::saveComfyUIMetadata($album, $comfyuiData);
                session()->forget($sessionKey);
                Log::info('Metadata saved to album', ['album_id' => $album->id, 'file_name' => $fileName]);
                break; // Only use first image's metadata
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

        $metadataFound = false;
        $disk = config('filesystems.default');

        // Check session for metadata from any image (extracted during upload)
        foreach ($images as $image) {
            $fileName = basename($image);
            $sessionKey = "image_metadata_{$fileName}";
            $comfyuiData = session()->get($sessionKey);

            if ($comfyuiData) {
                Log::info('Found metadata in session for image', ['album_id' => $album->id, 'file_name' => $fileName]);
                self::saveComfyUIMetadata($album, $comfyuiData);
                session()->forget($sessionKey);
                Log::info('Metadata updated from session', ['album_id' => $album->id, 'file_name' => $fileName]);
                $metadataFound = true;
                break; // Only use first image's metadata
            }
        }

        // If force refresh enabled and no session metadata found, try to extract from storage
        if (!$metadataFound && $forceRefresh) {
            Log::info('No session metadata found, attempting to extract from storage', ['album_id' => $album->id, 'disk' => $disk, 'image_count' => count($images)]);

            try {
                foreach ($images as $index => $image) {
                    $fileName = basename($image);
                    Log::debug('Attempting to extract metadata from image', ['album_id' => $album->id, 'image_index' => $index, 'file_name' => $fileName]);

                    $normalizedPath = self::getNormalizedImagePath($image);
                    Log::debug('Normalized image path', ['album_id' => $album->id, 'original' => $image, 'normalized' => $normalizedPath]);

                    try {
                        $encryptedContent = Storage::disk($disk)->get($normalizedPath);
                        Log::debug('Retrieved encrypted content from storage', ['album_id' => $album->id, 'file_name' => $fileName, 'size' => strlen($encryptedContent)]);

                        if (ImageService::isEncryptedDisk($disk)) {
                            Log::debug('Decrypting image content', ['album_id' => $album->id, 'file_name' => $fileName]);
                            try {
                                $contents = Crypt::decryptString($encryptedContent);
                                Log::debug('Successfully decrypted image', ['album_id' => $album->id, 'file_name' => $fileName, 'decrypted_size' => strlen($contents)]);
                            } catch (\Exception $decryptError) {
                                Log::warning('Failed to decrypt image', ['album_id' => $album->id, 'file_name' => $fileName, 'error' => $decryptError->getMessage()]);
                                continue;
                            }
                        } else {
                            Log::debug('Using unencrypted content', ['album_id' => $album->id, 'file_name' => $fileName]);
                            $contents = $encryptedContent;
                        }

                        Log::debug('Extracting metadata from decrypted/raw content', ['album_id' => $album->id, 'file_name' => $fileName, 'content_size' => strlen($contents)]);
                        $comfyuiData = self::extractMetadataFromContent($contents);

                        if ($comfyuiData) {
                            Log::info('Metadata extracted from storage', ['album_id' => $album->id, 'file_name' => $fileName, 'keys' => array_keys($comfyuiData)]);
                            self::saveComfyUIMetadata($album, $comfyuiData);
                            $metadataFound = true;
                            break; // Use first image with valid metadata
                        } else {
                            Log::info('Image has no embedded metadata - may not be a ComfyUI-generated image', [
                                'album_id' => $album->id,
                                'file_name' => $fileName,
                                'decrypted_size' => strlen($contents),
                                'suggestion' => 'Re-export image from ComfyUI with metadata embedding enabled'
                            ]);
                        }
                    } catch (\Exception $e) {
                        Log::warning('Error processing image for metadata', ['album_id' => $album->id, 'file_name' => $fileName, 'error' => $e->getMessage()]);
                        continue;
                    }
                }
            } catch (\Exception $e) {
                Log::warning('Force refresh metadata extraction failed', ['album_id' => $album->id, 'error' => $e->getMessage()]);
            }
        }

        if (!$metadataFound) {
            Log::info('updateMetadataFromImages completed (no metadata found)', ['album_id' => $album->id]);
        } else {
            Log::info('updateMetadataFromImages completed', ['album_id' => $album->id]);
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
        Log::info('extractMetadataFromContent started', ['content_size' => strlen($imageContent) ?? 0]);

        try {
            if (empty($imageContent)) {
                Log::warning('Empty image content provided for metadata extraction');
                return null;
            }

            // Verify PNG signature
            $pngSignature = substr($imageContent, 0, 8);
            $expectedSignature = "\x89PNG\r\n\x1a\n";
            if ($pngSignature !== $expectedSignature) {
                Log::warning('Invalid PNG signature detected', [
                    'expected' => bin2hex($expectedSignature),
                    'received' => bin2hex($pngSignature)
                ]);
                return null;
            }

            Log::debug('Valid PNG signature detected, extracting metadata');

            // Use an in-memory stream to avoid writing to disk
            Log::debug('Opening memory stream for PNG metadata extraction');
            $stream = fopen('php://memory', 'r+');
            if ($stream === false) {
                throw new \Exception('Unable to open memory stream for metadata extraction');
            }

            fwrite($stream, $imageContent);
            rewind($stream);

            Log::debug('Extracting PNG metadata from content');
            $comfyuiData = self::extractPNGMetadata($stream);

            fclose($stream);

            if (!empty($comfyuiData)) {
                Log::info('ComfyUI data extracted successfully from content', ['data_keys' => array_keys($comfyuiData)]);
            } else {
                Log::debug('No ComfyUI data found in image content', ['content_size' => strlen($imageContent)]);
            }

            return !empty($comfyuiData) ? $comfyuiData : null;

        } catch (\Exception $e) {
            Log::error('Error extracting ComfyUI data from content: ' . $e->getMessage());
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
            // Read PNG file and extract text chunks
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
                if (strlen($lengthData) < 4) break; // EOF reached

                $chunkLength = unpack('N', $lengthData)[1] ?? 0;
                $chunkType = fread($handle, 4);
                $chunkCount++;

                Log::debug('Processing PNG chunk', ['chunk_number' => $chunkCount, 'type' => $chunkType, 'length' => $chunkLength]);

                if ($chunkType === 'tEXt') {
                    $textChunkCount++;
                    $chunkData = fread($handle, $chunkLength);
                    $nullPos = strpos($chunkData, "\0");

                    if ($nullPos !== false) {
                        $keyword = substr($chunkData, 0, $nullPos);
                        $text = substr($chunkData, $nullPos + 1);

                        Log::debug('Found tEXt chunk', ['keyword' => $keyword, 'text_length' => strlen($text)]);

                        // Check for ComfyUI metadata keywords
                        if ($keyword === 'prompt' || $keyword === 'workflow') {
                            $jsonData = json_decode($text, true);
                            if (json_last_error() === JSON_ERROR_NONE && is_array($jsonData)) {
                                Log::info('Successfully parsed ComfyUI ' . $keyword, ['node_count' => count($jsonData)]);
                                $comfyuiData[$keyword] = $jsonData;
                            } else {
                                Log::warning('Failed to parse ' . $keyword . ' JSON', ['error' => json_last_error_msg()]);
                            }
                        }
                    }
                } else {
                    // Skip chunk data
                    if ($chunkLength > 0) {
                        fseek($handle, $chunkLength, SEEK_CUR);
                    }
                }

                // Skip CRC
                fseek($handle, 4, SEEK_CUR);

                if ($chunkType === 'IEND') break;
            }

            Log::debug('PNG metadata extraction complete', [
                'total_chunks' => $chunkCount,
                'text_chunks' => $textChunkCount,
                'metadata_found' => !empty($comfyuiData),
                'has_ihdr' => strpos(implode(',', array_column([], 0)), 'IHDR') !== false
            ]);

            if ($openedHere) {
                fclose($handle);
            }

            // Log specific warning if no tEXt chunks found (image without metadata)
            if ($textChunkCount === 0 && !empty($comfyuiData) === false) {
                Log::info('PNG image found but contains no text metadata chunks - image may not have been created with ComfyUI or metadata was stripped', [
                    'total_chunks' => $chunkCount,
                    'file_size_bytes' => strlen($GLOBALS['_png_content'] ?? 'unknown')
                ]);
            }
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
        $disk = config('filesystems.default');
        Log::info('saveComfyUIMetadata started', ['album_id' => $album->id, 'disk' => $disk]);

        // Save prompt + workflow in metadata field
        $metadataContent = "--- ComfyUI Metadata ---\n";
        if (isset($comfyuiData['prompt'])) {
            Log::debug('Adding prompt to metadata', ['album_id' => $album->id, 'disk' => $disk]);
            $metadataContent .= "Prompt:\n" . json_encode($comfyuiData['prompt'], JSON_PRETTY_PRINT) . "\n\n";
        }
        if (isset($comfyuiData['workflow'])) {
            Log::debug('Adding workflow to metadata', ['album_id' => $album->id, 'disk' => $disk]);
            $metadataContent .= "Workflow:\n" . json_encode($comfyuiData['workflow'], JSON_PRETTY_PRINT);
        }

        $album->metadata = $metadataContent;

        // Populate album fields from prompt data
        if (isset($comfyuiData['prompt'])) {
            Log::info('Parsing ComfyUI prompt to album fields', ['album_id' => $album->id, 'disk' => $disk]);
            self::parseComfyUIPromptToFields($album, $comfyuiData['prompt']);
        }

        $album->save();
        Log::info('saveComfyUIMetadata completed', ['album_id' => $album->id, 'disk' => $disk]);
    }

    /**
     * Parse ComfyUI prompt data and populate album fields
     */
    protected static function parseComfyUIPromptToFields(Album $album, array $promptData): void
    {
        $disk = config('filesystems.default');
        Log::info('parseComfyUIPromptToFields started', ['album_id' => $album->id, 'disk' => $disk, 'node_count' => count($promptData)]);

        // First pass: find KSampler node and get positive/negative references
        $positiveNodeId = null;
        $negativeNodeId = null;
        $loraNames = [];
        $dimensionsFound = false; // Track if we already found dimensions

        foreach ($promptData as $nodeId => $nodeData) {
            if (isset($nodeData['class_type']) && $nodeData['class_type'] === 'KSampler') {
                Log::info('Found KSampler node', ['album_id' => $album->id, 'disk' => $disk, 'node_id' => $nodeId]);

                $inputs = $nodeData['inputs'];

                // Extract sampling parameters
                if (isset($inputs['seed'])) {
                    Log::debug('Setting seed', ['album_id' => $album->id, 'disk' => $disk, 'seed' => $inputs['seed']]);
                    $album->seed = $inputs['seed'];
                }
                if (isset($inputs['steps'])) {
                    Log::debug('Setting steps', ['album_id' => $album->id, 'disk' => $disk, 'steps' => $inputs['steps']]);
                    $album->steps = $inputs['steps'];
                }
                if (isset($inputs['cfg'])) {
                    Log::debug('Setting cfg', ['album_id' => $album->id, 'disk' => $disk, 'cfg' => $inputs['cfg']]);
                    $album->cfg = $inputs['cfg'];
                }
                if (isset($inputs['sampler_name'])) {
                    Log::debug('Setting sampler_name', ['album_id' => $album->id, 'disk' => $disk, 'sampler_name' => $inputs['sampler_name']]);
                    $album->sampler_name = $inputs['sampler_name'];
                }
                if (isset($inputs['scheduler'])) {
                    Log::debug('Setting scheduler', ['album_id' => $album->id, 'disk' => $disk, 'scheduler' => $inputs['scheduler']]);
                    $album->scheduler = $inputs['scheduler'];
                }
                if (isset($inputs['denoise'])) {
                    Log::debug('Setting denoise', ['album_id' => $album->id, 'disk' => $disk, 'denoise' => $inputs['denoise']]);
                    $album->denoise = $inputs['denoise'];
                }

                // Get positive/negative node references - array format: [nodeId, outputIndex]
                if (isset($inputs['positive']) && is_array($inputs['positive']) && count($inputs['positive']) > 0) {
                    $positiveNodeId = (string) $inputs['positive'][0]; // Convert to string for comparison
                    Log::debug('Positive node reference found', ['album_id' => $album->id, 'disk' => $disk, 'node_id' => $positiveNodeId]);
                }
                if (isset($inputs['negative']) && is_array($inputs['negative']) && count($inputs['negative']) > 0) {
                    $negativeNodeId = (string) $inputs['negative'][0]; // Convert to string for comparison
                    Log::debug('Negative node reference found', ['album_id' => $album->id, 'disk' => $disk, 'node_id' => $negativeNodeId]);
                }

                Log::debug('KSampler references', ['album_id' => $album->id, 'positive_node_id' => $positiveNodeId, 'negative_node_id' => $negativeNodeId]);
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

            Log::debug('Processing node', ['album_id' => $album->id, 'disk' => $disk, 'node_id' => $nodeId, 'class_type' => $classType]);

            switch ($classType) {
                case 'CheckpointLoaderSimple':
                    // Extract model/checkpoint name
                    if (isset($inputs['ckpt_name'])) {
                        Log::debug('Setting checkpoint', ['album_id' => $album->id, 'disk' => $disk, 'ckpt_name' => $inputs['ckpt_name']]);
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

                        Log::debug('Adding LoRA', ['album_id' => $album->id, 'disk' => $disk, 'lora_name' => $name]);
                        $loraNames[] = $name;
                    }
                    break;

                case 'EmptyLatentImage':
                case 'EmptySD3LatentImage':
                    // Extract image dimensions - only from the first dimension node found
                    if (!$dimensionsFound) {
                        if (isset($inputs['width'])) {
                            Log::debug('Setting width', ['album_id' => $album->id, 'disk' => $disk, 'width' => $inputs['width']]);
                            $album->width = $inputs['width'];
                        }
                        if (isset($inputs['height'])) {
                            Log::debug('Setting height', ['album_id' => $album->id, 'disk' => $disk, 'height' => $inputs['height']]);
                            $album->height = $inputs['height'];
                        }
                        $dimensionsFound = true; // Don't extract from subsequent nodes
                    }
                    break;

                case 'CLIPTextEncode':
                    // Map prompts using KSampler references
                    if (isset($inputs['text'])) {
                        $text = $inputs['text'];
                        $currentNodeId = (string) $nodeId; // Ensure string comparison

                        if ($currentNodeId === $positiveNodeId) {
                            // This is the positive prompt - always update when extracting metadata
                            Log::debug('Updating positive prompt', ['album_id' => $album->id, 'disk' => $disk, 'node_id' => $currentNodeId, 'text_length' => strlen($text)]);
                            $album->positive = $text;
                        } elseif ($currentNodeId === $negativeNodeId) {
                            // This is the negative prompt - always update when extracting metadata
                            Log::debug('Updating negative prompt', ['album_id' => $album->id, 'disk' => $disk, 'node_id' => $currentNodeId, 'text_length' => strlen($text)]);
                            $album->negative = $text;
                        }
                    }
                    break;
            }
        }

        // Save LoRA names separated by comma
        if (!empty($loraNames)) {
            Log::info('Setting LoRAs', ['album_id' => $album->id, 'disk' => $disk, 'lora_count' => count($loraNames)]);
            $album->loras = implode(', ', $loraNames);
        }

        Log::info('parseComfyUIPromptToFields completed', ['album_id' => $album->id, 'disk' => $disk]);
    }
}
