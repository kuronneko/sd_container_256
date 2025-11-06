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
        Log::info('extractAndSaveMetadata started', ['album_id' => $album->id, 'image_count' => count($album->images ?? [])]);

        $disk = config('filesystems.default');
        $uploadFolder = $disk === 's3'
            ? config('filesystems.disks.s3.upload_folder', 'sd_develop')
            : '';

        $albumsDirectory = $disk === 's3'
            ? "{$uploadFolder}/albums"
            : "albums";

        // Get images from the record
        $images = $album->images ?? [];

        Log::debug('Processing album images', ['album_id' => $album->id, 'disk' => $disk, 'albums_directory' => $albumsDirectory, 'image_count' => count($images)]);

        foreach ($images as $image) {
            $fileName = basename($image);
            $imagePath = "{$albumsDirectory}/{$fileName}";

            Log::debug('Processing image', ['album_id' => $album->id, 'disk' => $disk, 'file_name' => $fileName]);

            if (Storage::disk($disk)->exists($imagePath)) {
                Log::info('Found image in albums folder', ['album_id' => $album->id, 'disk' => $disk, 'file_name' => $fileName]);
                Log::info('Extracting metadata from image', ['album_id' => $album->id, 'disk' => $disk, 'image_path' => $imagePath]);
                $comfyuiData = self::extractComfyUIData($imagePath, $disk);
                if ($comfyuiData) {
                    Log::info('ComfyUI data extracted, saving metadata', ['album_id' => $album->id, 'disk' => $disk]);
                    // Save prompt + workflow in metadata field and populate album fields
                    self::saveComfyUIMetadata($album, $comfyuiData);
                    Log::info('extractAndSaveMetadata completed (metadata found)', ['album_id' => $album->id, 'disk' => $disk]);
                    break; // Use data from first image with ComfyUI data
                }
            } else {
                Log::warning('Image not found in albums folder', ['album_id' => $album->id, 'disk' => $disk, 'file_name' => $fileName]);
            }
        }

        Log::info('extractAndSaveMetadata completed', ['album_id' => $album->id, 'disk' => $disk]);
    }

    /**
     * Update metadata from images for existing album
     */
    public static function updateMetadataFromImages(Album $album): void
    {
        Log::info('updateMetadataFromImages started', ['album_id' => $album->id, 'image_count' => count($album->images ?? [])]);

        $images = $album->images ?? [];

        if (empty($images)) {
            Log::info('No images found, skipping metadata update', ['album_id' => $album->id]);
            return;
        }

        // Get the first image to extract metadata from
        $firstImage = $images[0];
        $fileName = basename($firstImage);

        Log::debug('Extracting metadata from first image', ['album_id' => $album->id, 'file_name' => $fileName]);

        $disk = config('filesystems.default');
        $uploadFolder = $disk === 's3'
            ? config('filesystems.disks.s3.upload_folder', 'sd_develop')
            : '';

        // Check albums folder
        $imagePath = null;
        $albumsPath = $disk === 's3'
            ? "{$uploadFolder}/albums/{$fileName}"
            : "albums/{$fileName}";

        if (Storage::disk($disk)->exists($albumsPath)) {
            $imagePath = $albumsPath;
            Log::debug('Found image in albums folder', ['album_id' => $album->id, 'disk' => $disk, 'file_name' => $fileName]);
        } else {
            Log::warning('Image not found in albums folder', ['album_id' => $album->id, 'disk' => $disk, 'file_name' => $fileName]);
        }

        if ($imagePath) {
            Log::info('Extracting ComfyUI data', ['album_id' => $album->id, 'disk' => $disk, 'image_path' => $imagePath]);
            $comfyuiData = self::extractComfyUIData($imagePath, $disk);
            if ($comfyuiData) {
                Log::info('ComfyUI data found, saving metadata', ['album_id' => $album->id, 'disk' => $disk]);
                self::saveComfyUIMetadata($album, $comfyuiData);
            } else {
                Log::info('No ComfyUI data found in image', ['album_id' => $album->id, 'disk' => $disk]);
            }
        }

        Log::info('updateMetadataFromImages completed', ['album_id' => $album->id, 'disk' => $disk]);
    }

    /**
     * Extract ComfyUI data from image file
     */
    protected static function extractComfyUIData(string $imagePath, string $disk): ?array
    {
        Log::info('extractComfyUIData started', ['disk' => $disk, 'image_path' => $imagePath]);

        try {
            Log::debug('Reading image content', ['disk' => $disk, 'image_path' => $imagePath]);
            $imageContent = Storage::disk($disk)->get($imagePath);

            // If the image is stored encrypted on configured disks, try to decrypt it first.
            // If decryption fails, abort metadata extraction for this image (to avoid
            // processing ciphertext and producing misleading errors).
            if (ImageService::isEncryptedDisk($disk)) {
                Log::debug('Disk is encrypted, attempting decryption', ['disk' => $disk, 'image_path' => $imagePath]);
                try {
                    $decoded = Crypt::decryptString($imageContent);
                    $imageContent = base64_decode($decoded);
                    Log::debug('Image decrypted successfully', ['disk' => $disk, 'image_path' => $imagePath]);
                } catch (\Exception $e) {
                    Log::warning('Skipping metadata extraction for ' . $imagePath . ': decryption failed.', ['disk' => $disk]);
                    return null;
                }
            }
            // Use an in-memory stream instead of a temporary file to avoid writing
            // decrypted image bytes to disk.
            Log::debug('Opening memory stream for PNG metadata extraction', ['disk' => $disk, 'image_path' => $imagePath]);
            $stream = fopen('php://memory', 'r+');
            if ($stream === false) {
                throw new \Exception('Unable to open memory stream for metadata extraction');
            }

            fwrite($stream, $imageContent);
            rewind($stream);

            Log::debug('Extracting PNG metadata', ['disk' => $disk, 'image_path' => $imagePath]);
            $comfyuiData = self::extractPNGMetadata($stream);

            fclose($stream);

            if (!empty($comfyuiData)) {
                Log::info('ComfyUI data extracted successfully', ['disk' => $disk, 'image_path' => $imagePath, 'data_keys' => array_keys($comfyuiData)]);
            } else {
                Log::debug('No ComfyUI data found in image', ['disk' => $disk, 'image_path' => $imagePath]);
            }

            return !empty($comfyuiData) ? $comfyuiData : null;

        } catch (\Exception $e) {
            Log::error('Error extracting ComfyUI data from ' . $imagePath . ': ' . $e->getMessage(), ['disk' => $disk]);
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

            if ($openedHere) {
                fclose($handle);
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

                // Get positive/negative node references
                if (isset($inputs['positive']) && is_array($inputs['positive'])) {
                    $positiveNodeId = $inputs['positive'][0]; // First element is node ID
                    Log::debug('Positive node reference', ['album_id' => $album->id, 'disk' => $disk, 'node_id' => $positiveNodeId]);
                }
                if (isset($inputs['negative']) && is_array($inputs['negative'])) {
                    $negativeNodeId = $inputs['negative'][0]; // First element is node ID
                    Log::debug('Negative node reference', ['album_id' => $album->id, 'disk' => $disk, 'node_id' => $negativeNodeId]);
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

                        if ($nodeId == $positiveNodeId) {
                            // This is the positive prompt
                            if (empty($album->positive)) {
                                Log::debug('Setting positive prompt', ['album_id' => $album->id, 'disk' => $disk, 'text_length' => strlen($text)]);
                                $album->positive = $text;
                            }
                        } elseif ($nodeId == $negativeNodeId) {
                            // This is the negative prompt
                            if (empty($album->negative)) {
                                Log::debug('Setting negative prompt', ['album_id' => $album->id, 'disk' => $disk, 'text_length' => strlen($text)]);
                                $album->negative = $text;
                            }
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
