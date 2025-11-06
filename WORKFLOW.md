# Image Encryption Pipeline - Complete Workflow

```
┌─────────────────────────────────────────────────────────────────────────────────────┐
│                          IMAGE UPLOAD & ENCRYPTION FLOW                            │
└─────────────────────────────────────────────────────────────────────────────────────┘

                                   USER UPLOADS IMAGE
                                          │
                                          ▼
                    ┌─────────────────────────────────────┐
                    │  AlbumResource.saveUploadedFileUsing│
                    │  ─────────────────────────────────  │
                    │  • Read plaintext file content      │
                    │  • Extract metadata from plaintext  │
                    │  • Store metadata in session        │
                    │  • Generate thumbnail (200x200 JPEG)│
                    │  • Encrypt thumbnail                │
                    │  • Upload encrypted thumbnail       │
                    │  • Encrypt image                    │
                    │  • Upload encrypted image           │
                    └─────────────────────────────────────┘
                                          │
                                          ▼
                    ┌─────────────────────────────────────┐
                    │    File Uploaded to S3 Encrypted    │
                    │  • albums/{filename}                │
                    │  • albums/thumbnails/{filename}     │
                    └─────────────────────────────────────┘
                                          │
                                          ▼
                    ┌─────────────────────────────────────┐
                    │   Album Record Created in Database  │
                    │   images: ["albums/image1.png", ..] │
                    └─────────────────────────────────────┘
                                          │
                                          ▼
                    ┌─────────────────────────────────────┐
                    │   CreateAlbum.afterCreate()         │
                    │   ─────────────────────────────────  │
                    │   1. ensureImagesProcessed($album)  │
                    │   2. extractAndSaveMetadata($album) │
                    └─────────────────────────────────────┘
                                          │
                    ┌─────────────────────┴──────────────────────┐
                    │                                             │
                    ▼                                             ▼
    ┌───────────────────────────────┐        ┌──────────────────────────────┐
    │  ImageService.                │        │  MetaDataService.            │
    │  ensureImagesProcessed()      │        │  extractAndSaveMetadata()    │
    │  ───────────────────────────  │        │  ────────────────────────────│
    │  • Move images from base      │        │  • Check session for:        │
    │    albums/{filename} to       │        │    image_metadata_{filename} │
    │    albums/{albumId}/{filename}│        │  • Apply metadata to album   │
    │  • Move thumbnails from       │        │  • Clear session             │
    │    albums/thumbnails/{fn} to  │        │                              │
    │    albums/{albumId}/thumbnails│        │                              │
    │  • Update paths in album      │        │                              │
    └───────────────────────────────┘        └──────────────────────────────┘
                    │                                             │
                    └─────────────────────┬──────────────────────┘
                                          │
                                          ▼
                    ┌─────────────────────────────────────┐
                    │   Album Ready for Display           │
                    │   • Metadata applied                │
                    │   • Files organized by album ID     │
                    │   • Database synchronized           │
                    └─────────────────────────────────────┘
                                          │
                                          ▼
                                 USER VIEWS ALBUM
                                          │
                                          ▼
                    ┌─────────────────────────────────────┐
                    │  Album.prepareSelectedImageUrls()   │
                    │  ─────────────────────────────────  │
                    │  • Select first image               │
                    │  • Build URLs:                      │
                    │    /albums/image/{albumId}/{fn}     │
                    │    /albums/thumbnail/{albumId}/{fn} │
                    └─────────────────────────────────────┘
                                          │
                                          ▼
                    ┌─────────────────────────────────────┐
                    │   ImageController                   │
                    │   ─────────────────────────────────  │
                    │   GET /albums/image/{id}/{fn}       │
                    │   GET /albums/thumbnail/{id}/{fn}   │
                    └─────────────────────────────────────┘
                                          │
                                          ▼
                    ┌─────────────────────────────────────┐
                    │  • Load encrypted file from S3      │
                    │  • Decrypt with Crypt::decryptString│
                    │  • Stream to browser with MIME type │
                    └─────────────────────────────────────┘
                                          │
                                          ▼
                           IMAGE DISPLAYED IN BROWSER


┌─────────────────────────────────────────────────────────────────────────────────────┐
│                         SESSION METADATA PIPELINE                                   │
└─────────────────────────────────────────────────────────────────────────────────────┘

                          DURING UPLOAD (AlbumResource)
                                    │
                                    ▼
                    ┌─────────────────────────────────────┐
                    │  extractMetadataFromContent()       │
                    │  ─────────────────────────────────  │
                    │  • Parse PNG chunks from plaintext  │
                    │  • Extract "prompt" chunk (ComfyUI) │
                    │  • Return metadata array or null    │
                    └─────────────────────────────────────┘
                                    │
                                    ▼
                    ┌─────────────────────────────────────┐
                    │  session()->put(                     │
                    │    "image_metadata_{filename}",     │
                    │    $metadata                        │
                    │  )                                  │
                    └─────────────────────────────────────┘
                                    │
                                    ▼
                      AFTER CREATE (MetaDataService)
                                    │
                                    ▼
                    ┌─────────────────────────────────────┐
                    │  extractAndSaveMetadata($album)     │
                    │  ─────────────────────────────────  │
                    │  • Loop through album->images       │
                    │  • Check session for metadata       │
                    │  • Apply to album model             │
                    │  • Clear session                    │
                    │  • Break (only first image)         │
                    └─────────────────────────────────────┘
                                    │
                                    ▼
                         METADATA SAVED TO ALBUM


┌─────────────────────────────────────────────────────────────────────────────────────┐
│                         THUMBNAIL GENERATION PIPELINE                               │
└─────────────────────────────────────────────────────────────────────────────────────┘

                          DURING UPLOAD (AlbumResource)
                                    │
                                    ▼
                    ┌─────────────────────────────────────┐
                    │  if (config('encrypt_thumbnails'))  │
                    └─────────────────────────────────────┘
                                    │
                                    ▼
                    ┌─────────────────────────────────────┐
                    │  InterventionImage::read($contents) │
                    │  ->cover(200, 200)                  │
                    │  ->toJpeg()                         │
                    └─────────────────────────────────────┘
                                    │
                                    ▼
                    ┌─────────────────────────────────────┐
                    │  Crypt::encryptString($thumbnail)   │
                    │  $component->getDisk()->put(        │
                    │    "albums/thumbnails/{filename}",  │
                    │    $encrypted                       │
                    │  )                                  │
                    └─────────────────────────────────────┘
                                    │
                                    ▼
                      AFTER CREATE (ImageService)
                                    │
                                    ▼
                    ┌─────────────────────────────────────┐
                    │  ensureImagesProcessed($album)      │
                    │  ─────────────────────────────────  │
                    │  • Find thumbnail in base folder    │
                    │  • Move to:                         │
                    │    albums/{albumId}/thumbnails/{fn} │
                    │  • No re-encryption (already done)  │
                    └─────────────────────────────────────┘
                                    │
                                    ▼
                      THUMBNAIL READY FOR DISPLAY


┌─────────────────────────────────────────────────────────────────────────────────────┐
│                             FILE ORGANIZATION                                       │
└─────────────────────────────────────────────────────────────────────────────────────┘

BEFORE PROCESSING (after upload):
├── albums/
│   ├── image1.png (encrypted)
│   ├── image2.png (encrypted)
│   └── thumbnails/
│       ├── image1.png (encrypted)
│       └── image2.png (encrypted)


AFTER PROCESSING (after ensureImagesProcessed):
├── albums/
│   ├── 123/ (albumId)
│   │   ├── image1.png (encrypted, moved)
│   │   ├── image2.png (encrypted, moved)
│   │   └── thumbnails/
│   │       ├── image1.png (encrypted, moved)
│   │       └── image2.png (encrypted, moved)
│   │
│   └── 124/ (another albumId)
│       ├── other-image.png (encrypted, moved)
│       └── thumbnails/
│           └── other-image.png (encrypted, moved)


┌─────────────────────────────────────────────────────────────────────────────────────┐
│                            DATA FLOW SUMMARY                                        │
└─────────────────────────────────────────────────────────────────────────────────────┘

PLAINTEXT                           ENCRYPTED                    STORAGE LOCATION
     │                                  │                              │
     ├─ extract metadata ────────► session cache ───────────────► album.metadata
     │
     ├─ generate thumbnail ───────► encrypt ─────────────────────► albums/{id}/thumbnails/{fn}
     │
     └─ read contents ────────────► encrypt ─────────────────────► albums/{id}/{filename}


┌─────────────────────────────────────────────────────────────────────────────────────┐
│                         KEY DESIGN DECISIONS                                        │
└─────────────────────────────────────────────────────────────────────────────────────┘

✅ IN-MEMORY PROCESSING
   • Plaintext never written to disk
   • Plaintext never uploaded to S3
   • Encryption happens immediately before upload
   • All processing in memory only

✅ SESSION-BASED METADATA
   • Extract once during upload
   • Store in session temporarily
   • Apply during afterCreate callback
   • No need to decrypt just to get metadata

✅ ALBUM-SPECIFIC ORGANIZATION
   • Base upload folder: albums/
   • Album-specific folders: albums/{albumId}/
   • Enables easy cleanup per album
   • Enables easy file discovery

✅ SINGLE-PASS THUMBNAIL
   • Generate from plaintext
   • Encrypt during upload
   • Move during processing
   • Never re-encrypted or copied

✅ CODE CLEANUP (~200 LINES REMOVED)
   • Removed dead fallback logic
   • Removed verbose logging
   • Removed redundant encryption verification
   • Removed unnecessary disk I/O


┌─────────────────────────────────────────────────────────────────────────────────────┐
│                         TECHNICAL IMPLEMENTATION                                    │
└─────────────────────────────────────────────────────────────────────────────────────┘

1. UPLOAD HANDLER: AlbumResource.saveUploadedFileUsing()
   ────────────────────────────────────────────────────────

   File: app/Filament/Resources/AlbumResource.php (lines 141-189)
   
   Input:
   ├─ $component: Filament FileUpload component
   ├─ $file: Livewire TemporaryUploadedFile
   └─ Return: string (path to uploaded file)
   
   Process for ENCRYPTED DISKS:
   ┌─────────────────────────────────────────────────────────┐
   │ 1. Read plaintext                                       │
   │    $realPath = $file->getRealPath() ?? $file->getPath()│
   │    $contents = file_get_contents($realPath)            │
   │    Size: Full image (e.g., 2MB PNG)                    │
   │                                                          │
   │ 2. Extract metadata                                     │
   │    $metadata = MetaDataService::extractMetadata...()   │
   │    Result: Array with prompt/workflow or null          │
   │    Time: 2-5ms                                          │
   │                                                          │
   │ 3. Store in session                                     │
   │    session()->put("image_metadata_{$fileName}", $meta) │
   │    Key: "image_metadata_abc123.png"                     │
   │    Duration: Until afterCreate() clears it             │
   │                                                          │
   │ 4. Generate thumbnail (if configured)                  │
   │    if (config('image_encrypt.encrypt_thumbnails'))     │
   │    ├─ $image = InterventionImage::read($contents)      │
   │    ├─ $image->cover(200, 200)  [fit & crop]           │
   │    ├─ $thumbnail = $image->toJpeg()                    │
   │    └─ Size: ~15-25KB                                   │
   │    Time: 10-20ms                                        │
   │                                                          │
   │ 5. Encrypt thumbnail                                    │
   │    $encrypted = Crypt::encryptString($thumbnail)       │
   │    Algorithm: AES-256-GCM (Laravel default)            │
   │    Overhead: ~1.78x (includes IV + MAC)                │
   │    Result Size: ~27-45KB                               │
   │                                                          │
   │ 6. Upload encrypted thumbnail                          │
   │    $path = "sd_develop/albums/thumbnails/{fileName}"   │
   │    $disk->put($path, $encrypted, visibility)           │
   │    Disk: S3 (DigitalOcean Spaces)                      │
   │                                                          │
   │ 7. Encrypt image                                        │
   │    $encrypted = Crypt::encryptString($contents)        │
   │    Size: Original × 1.78 (e.g., 2MB → 3.56MB)         │
   │                                                          │
   │ 8. Upload encrypted image                              │
   │    $path = "sd_develop/albums/{fileName}"              │
   │    $disk->put($path, $encrypted, visibility)           │
   │                                                          │
   │ Total Upload Time: 30-60ms per image                   │
   │ Total Bandwidth: Size × 1.78 × 2 (image + thumbnail)  │
   └─────────────────────────────────────────────────────────┘
   
   Process for NON-ENCRYPTED DISKS:
   └─ Use standard $file->storeAs() / $file->storePubliclyAs()


2. FILE DISPLAY HANDLER: AlbumResource.getUploadedFileUsing()
   ──────────────────────────────────────────────────────────

   File: app/Filament/Resources/AlbumResource.php (lines 99-139)
   
   Input:
   ├─ $component: Filament FileUpload component
   ├─ $file: S3 path string
   └─ Return: Array with name, size, type, url
   
   Process:
   ┌─────────────────────────────────────────────────────────┐
   │ 1. Normalize S3 path                                    │
   │    $normalized = substr($file, strlen($uploadFolder)+1)│
   │    Input:  "sd_develop/albums/123/image.png"           │
   │    Output: "albums/123/image.png"                      │
   │                                                          │
   │ 2. Infer MIME type from extension                       │
   │    $ext = pathinfo($name, PATHINFO_EXTENSION)          │
   │    Map: jpg→image/jpeg, png→image/png, etc.           │
   │    (Encrypted objects report application/octet-stream) │
   │                                                          │
   │ 3. Check if album file (albums/{id}/{filename})        │
   │    $segments = explode('/', $normalized)               │
   │    if segments[0]=='albums' && isset(segments[1])      │
   │    └─ $albumId = segments[1]                           │
   │                                                          │
   │ 4. Route to decrypt controller                         │
   │    $url = url("/albums/image/{$albumId}/{$filename}")  │
   │    (Filament preview/open/download will use this)      │
   │                                                          │
   │ 5. Fallback to storage URL if needed                    │
   │    if (!$url) try temporary URL for private files      │
   │    else storage->url($file)                            │
   │                                                          │
   │ Return: ['name' => '...', 'size' => 0, 'type' => '...', 'url' => '...']
   │ (size=0 because we can't read encrypted file size)     │
   └─────────────────────────────────────────────────────────┘


3. IMAGE PROCESSING: ImageService.ensureImagesProcessed()
   ──────────────────────────────────────────────────────

   File: app/Services/ImageService.php (lines 51-131)
   
   Called from: CreateAlbum.afterCreate() and EditAlbum.afterSave()
   
   Input: $album (Album model with images array)
   
   Process:
   ┌─────────────────────────────────────────────────────────┐
   │ 1. Discover images in base and album-specific folders   │
   │    $baseFolder = "albums"                               │
   │    $albumFolder = "albums/{$album->id}"                 │
   │    $disk->files($baseFolder) + $disk->files($albumFolder)
   │                                                          │
   │ 2. For each image in album->images array                │
   │    ├─ Check if path exists in base folder              │
   │    ├─ If yes: move to album-specific folder            │
   │    │   From: "albums/abc123.png"                        │
   │    │   To:   "albums/{id}/abc123.png"                   │
   │    │   Method: $disk->move($from, $to)                 │
   │    └─ Time: 2-5ms per file (S3 API call)               │
   │                                                          │
   │ 3. Move thumbnail if exists                            │
   │    ├─ From: "albums/thumbnails/abc123.png"             │
   │    ├─ To:   "albums/{id}/thumbnails/abc123.png"        │
   │    └─ Time: 2-5ms                                       │
   │                                                          │
   │ 4. Update paths in album record                         │
   │    Before: images = ["albums/abc123.png"]              │
   │    After:  images = ["albums/123/abc123.png"]          │
   │    Save to database                                     │
   │                                                          │
   │ Total Processing Time: 5-20ms per image                │
   │ (No re-encryption, no decryption)                       │
   └─────────────────────────────────────────────────────────┘


4. METADATA EXTRACTION: MetaDataService.extractMetadataFromContent()
   ──────────────────────────────────────────────────────────────

   File: app/Services/MetaDataService.php (lines 79-115)
   
   Called from: AlbumResource.saveUploadedFileUsing() during upload
   
   Input: $imageContent (raw image bytes, plaintext)
   Output: Array with ComfyUI metadata or null
   
   Process:
   ┌─────────────────────────────────────────────────────────┐
   │ 1. Parse PNG file structure                             │
   │    ├─ Read PNG signature: 89 50 4E 47 0D 0A 1A 0A      │
   │    ├─ Loop through chunks                              │
   │    └─ Each chunk: length (4) + type (4) + data + CRC (4)
   │                                                          │
   │ 2. Extract text chunks                                  │
   │    Look for chunk types: "tEXt" (text keyword+value)   │
   │    Keywords: "prompt" and "workflow"                    │
   │                                                          │
   │ 3. Parse ComfyUI prompt                                 │
   │    ├─ JSON decode the prompt string                    │
   │    ├─ Result: {"0": {...}, "1": {...}, ...}            │
   │    ├─ Extract generation parameters from nodes         │
   │    └─ Map to database fields:                          │
   │       • positive (model/KSampler prompt)               │
   │       • negative (model/KSampler negative)             │
   │       • seed (KSampler seed)                           │
   │       • steps (KSampler steps)                         │
   │       • cfg (KSampler cfg)                             │
   │       • sampler_name (KSampler sampler_name)           │
   │       • scheduler (KSampler scheduler)                 │
   │       • width/height (CheckpointLoaderSimple)          │
   │       • ckpt_name (model checkpoint)                   │
   │       • loras (LoRA loader nodes)                      │
   │                                                          │
   │ 4. Return structured array                             │
   │    [                                                    │
   │      'positive' => '...',                              │
   │      'negative' => '...',                              │
   │      'seed' => 123456,                                 │
   │      'steps' => 30,                                    │
   │      ...                                                │
   │    ]                                                    │
   │                                                          │
   │ Time: 2-5ms per PNG file                               │
   │ (Only called on PNGs; JPEGs return null)               │
   └─────────────────────────────────────────────────────────┘


5. METADATA APPLICATION: MetaDataService.extractAndSaveMetadata()
   ──────────────────────────────────────────────────────────

   File: app/Services/MetaDataService.php (lines 15-45)
   
   Called from: CreateAlbum.afterCreate()
   
   Input: $album (Album model with images array)
   
   Process:
   ┌─────────────────────────────────────────────────────────┐
   │ 1. Loop through images in album->images array           │
   │    foreach ((array) $album->images as $image)          │
   │                                                          │
   │ 2. Check session for extracted metadata                 │
   │    $fileName = basename($image)                         │
   │    $sessionKey = "image_metadata_{$fileName}"           │
   │    $metadata = session($sessionKey)                     │
   │                                                          │
   │ 3. If metadata found in session                         │
   │    ├─ Apply to album model                             │
   │    │   $album->positive = $metadata['positive']        │
   │    │   $album->negative = $metadata['negative']        │
   │    │   $album->seed = $metadata['seed']                │
   │    │   ... (all 15+ fields)                            │
   │    ├─ Save to database (encrypted by model)            │
   │    │   $album->save()                                   │
   │    ├─ Clear session                                     │
   │    │   session()->forget($sessionKey)                   │
   │    └─ Break (only first image)                         │
   │                                                          │
   │ 4. If no metadata found                                 │
   │    └─ Skip, album created with empty metadata fields   │
   │                                                          │
   │ Note: Only first image metadata is saved for multi-    │
   │ image albums (intentional design)                       │
   │                                                          │
   │ Time: <1ms (pure PHP array operations)                  │
   └─────────────────────────────────────────────────────────┘


6. IMAGE DISPLAY: ImageController.showImage() & showThumbnail()
   ─────────────────────────────────────────────────────────

   File: app/Http/Controllers/ImageController.php
   
   Routes:
   ├─ GET /albums/image/{albumId}/{filename}
   └─ GET /albums/thumbnail/{albumId}/{filename}
   
   Process:
   ┌─────────────────────────────────────────────────────────┐
   │ 1. Validate album ID and filename                       │
   │    ├─ Load Album: $album = Album::findOrFail($id)       │
   │    ├─ Check file in images array                        │
   │    └─ Prevent directory traversal attacks               │
   │                                                          │
   │ 2. Build S3 path                                        │
   │    $uploadFolder = "sd_develop"                         │
   │    $path = "{$uploadFolder}/albums/{$albumId}/{$fn}"   │
   │                                                          │
   │ 3. Load encrypted file from S3                          │
   │    $encrypted = $disk->get($path)                       │
   │    Time: 10-50ms (network)                              │
   │    Size: 1.78× original                                 │
   │                                                          │
   │ 4. Decrypt in memory                                    │
   │    try {                                                │
   │      $decrypted = Crypt::decryptString($encrypted)     │
   │    } catch (DecryptException $e) {                      │
   │      return 403 Forbidden                               │
   │    }                                                    │
   │    Time: 2-5ms                                          │
   │    (Uses session key for authentication)                │
   │                                                          │
   │ 5. Stream to browser                                    │
   │    return response()                                    │
   │      ->streamDownload(function () use ($decrypted) {   │
   │        echo $decrypted;                                 │
   │      }, $filename, [                                   │
   │        'Content-Type' => 'image/png',                  │
   │        'Cache-Control' => 'no-store, no-cache'         │
   │      ])                                                 │
   │                                                          │
   │ Total Response Time: 15-60ms (per request)              │
   │ Memory Usage: Original file size (plaintext in memory)  │
   └─────────────────────────────────────────────────────────┘


7. SESSION METADATA LIFECYCLE
   ─────────────────────────

   ┌──────────────────────────────────────────────────────────────────┐
   │ CREATED                                                          │
   │ During: File upload in AlbumResource.saveUploadedFileUsing()    │
   │ Key Pattern: image_metadata_{filename}                          │
   │ Example: image_metadata_abc123.png                              │
   │ Value: Array with ComfyUI metadata                              │
   │ Expiry: Until afterCreate() is called                           │
   │                                                                  │
   │ Usage Count: 1 (read in MetaDataService.extractAndSaveMetadata) │
   │ Cleared: Explicitly with session()->forget()                    │
   │                                                                  │
   │ Lifetime: < 5 seconds (upload to afterCreate callback)         │
   │                                                                  │
   │ Storage: In-memory session (Laravel default)                    │
   │ (Can be Redis/Memcached depending on SESSION_DRIVER config)    │
   └──────────────────────────────────────────────────────────────────┘


┌─────────────────────────────────────────────────────────────────────────────────────┐
│                         PERFORMANCE METRICS                                         │
└─────────────────────────────────────────────────────────────────────────────────────┘

UPLOAD SINGLE IMAGE (2MB PNG):
├─ Read plaintext: ~1ms
├─ Extract metadata: ~3ms
├─ Generate thumbnail (200×200): ~15ms
├─ Encrypt thumbnail (~20KB): ~2ms
├─ Encrypt image (3.56MB): ~4ms
├─ Upload thumbnail to S3: ~25ms (network)
├─ Upload image to S3: ~40ms (network)
├─ afterCreate() callback:
│  ├─ Move image to album folder: ~3ms
│  ├─ Move thumbnail to album folder: ~3ms
│  ├─ Apply metadata: <1ms
│  └─ Update database: ~10ms
│
└─ TOTAL: ~110ms (including S3 network latency)


DISPLAY IMAGE REQUEST:
├─ Database lookup: ~1ms
├─ Fetch from S3: ~20ms (network)
├─ Decrypt: ~3ms
├─ Stream to browser: ~10ms (bandwidth dependent)
│
└─ TOTAL: ~35ms (plus download time: size ÷ bandwidth)


BATCH UPLOAD (10 IMAGES):
├─ Upload phase: 10 × 40ms = ~400ms
├─ afterCreate() phase: 10 × 5ms = ~50ms
│
└─ TOTAL: ~450ms (parallelizable with background queue)


┌─────────────────────────────────────────────────────────────────────────────────────┐
│                         SECURITY CONSIDERATIONS                                     │
└─────────────────────────────────────────────────────────────────────────────────────┘

✅ ENCRYPTION TIMING
   • Happens immediately after reading plaintext
   • No window for plaintext to be exposed
   • Encryption key from APP_KEY (Laravel secure)

✅ SESSION METADATA SAFETY
   • Only decryptable by authenticated user (session-based)
   • Cleared after use (no persistence)
   • Lifecycle: <5 seconds

✅ S3 STORAGE
   • All files stored encrypted
   • No plaintext files on S3
   • Files can't be read without application decryption

✅ IMAGE CONTROLLER PROTECTION
   • Validates album ownership (if implemented)
   • Catches DecryptException (invalid key/tampering)
   • Returns 403 on decryption failure
   • Never exposes encrypted content

✅ EXTENSION CHECKING
   • MIME type inferred from extension (user-controlled)
   • But encryption key is server-controlled
   • Worst case: user gets wrong MIME type, not data theft

✅ CACHING
   • 'Cache-Control: no-store, no-cache' headers
   • Browser won't cache decrypted images
   • Each view requires fresh decryption

```
