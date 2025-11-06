# Image Encryption & Metadata Workflow

## Overview
Images are encrypted **in-memory before upload** to S3, with metadata extracted from plaintext to avoid redundant decryption.

## Complete Workflow

### 1. **Upload Phase** (AlbumResource.php)
```
User uploads image file
  ↓
Read plaintext file content
  ↓
Extract metadata from plaintext → Store in session
  ↓
Encrypt content in-memory
  ↓
Upload encrypted file to S3
```

### 2. **Processing Phase** (CreateAlbum.php → ImageService)
```
Album created
  ↓
ensureImagesProcessed():
  - Move images from base folder to album-specific folder
  - Verify encryption (already encrypted from upload)
  - Generate encrypted thumbnails
  - Retrieve metadata from session → Apply to album
  - Clear session
```

### 3. **Metadata Phase** (CreateAlbum.php → MetaDataService)
```
extractAndSaveMetadata():
  ✓ Check session for pre-extracted metadata (FOUND)
  - Apply and save
  OR
  ✗ Check session (NOT FOUND)
  - Fallback: Decrypt image from disk and extract
```

## Key Benefits
- ✅ **No unencrypted files** touch S3
- ✅ **No redundant decryption** - metadata extracted once during upload
- ✅ **Album-specific organization** - images stored at `albums/{albumId}/{filename}`
- ✅ **Thumbnails encrypted** - encrypted storage with encryption overhead ~1.78x
- ✅ **Fallback support** - handles images uploaded via other paths

## File Paths
- **Encrypted images**: `s3://sd_develop/albums/{albumId}/{filename}`
- **Encrypted thumbnails**: `s3://sd_develop/albums/{albumId}/thumbnails/{filename}`
- **Decrypted streaming**: Routes `/albums/image/{albumId}/{filename}` → ImageController

## Configuration
- `config/image_encrypt.php`: `encrypt_thumbnails => true`, `encrypted_disks => ['s3', 'public']`
