# Secure Data Container 256 — Image & Album Manager (Laravel)

This repository is a Laravel-based web application that manages albums and images. It includes a full encrypted-image pipeline (in-memory encryption before upload), session-based multi image metadata extraction (ComfyUI/PNG prompt parsing), thumbnail generation, and a streaming preview system that decrypts images in-memory for browser delivery without ever writing plaintext to disk. The app also uses mutators/accessors (or encrypted casts) on the `Album` model to persist generation fields (prompt, seed, sampler, etc.) safely in the database. It also supports indexed search caching.

Key components you will find in the codebase:

-   Albums and Image management (models, migrations, controllers)
-   Encrypted image pipeline: in-memory encrypt-before-upload, thumbnail generation, and post-create processing (see `WORKFLOW.md` for the full diagram and timings)
-   `app/Services/ImageService.php` — image resizing/processing, moving uploaded files into album folders, and thumbnail handling
-   `app/Services/MetaDataService.php` — extraction and handling of image metadata (PNG tEXt chunks / ComfyUI prompts), applying metadata to albums after create, and support for multi-image uploads (per-file session keys like `image_metadata_{filename}`; can aggregate or persist per-image metadata or album-level fields)
-   `app/Http/Controllers/ImageController.php` — streaming preview endpoints that load encrypted objects from storage, decrypt in-memory, and stream to the client
-   Observers (e.g., `app/Observers/AlbumObserver.php`) for keeping related state in sync (triggers for `ensureImagesProcessed()` and metadata application)
-   Filament admin resources under `app/Filament/Resources` for admin UI (upload hooks, preview integration)
-   Migrations that include albums, images, loras, comments and super user table additions
-   `app/Services/SearchCacheService.php` — lightweight indexing/cache for album and image metadata to speed searches and reduce DB load

This README explains how to install, run, and test the project locally or using Docker.

## Table of contents

-   [Requirements](#requirements)
-   [Quick start (local)](#quick-start-local-development)
-   [Docker / docker-compose](#docker--docker-compose)
-   [Getting started — Installation flows](#getting-started--installation-flows)
-   [Environment variables](#environment-variables)
-   [Database migrations & seeding](#database-migrations--seeding)
-   [Assets (Vite) (Optional)](#assets-vite--optional)
-   [Troubleshooting](#troubleshooting)
-   [Project structure highlights](#project-structure-highlights)
-   [Encrypted image workflow](#encrypted-image-workflow-upload-metadata-streaming-preview)
-   [Contributing](#contributing)
-   [License](#license)

## Requirements

-   PHP 8.2+
-   Composer (for PHP dependencies)
-   Node.js + npm (or yarn) for frontend assets (Vite) — optional (only required if you need to build or modify frontend assets)
-   A database (MySQL, PostgreSQL, or SQLite for quick local testing)
-   Optional: Docker & docker-compose (a `docker-compose.yml` exists in the repo)

## Quick start (local development)

1. Clone the repository

    ```zsh
    git clone <repo-url> sd_container_256
    cd sd_container_256
    ```

2. Install PHP dependencies

    ```zsh
    composer install --no-interaction --prefer-dist
    ```

3. (Optional) Install JavaScript dependencies

    ```zsh
    # Only necessary if you will build or modify frontend assets
    # npm install
    # or: yarn
    ```

4. Copy the environment file and set environment variables

    ```zsh
    cp .env.example .env
    php artisan key:generate
    ```

    Edit `.env` and set your database connection, filesystem disk (local or s3), and any AWS credentials if you use S3. Example DB section:

    ```env
    DB_CONNECTION=mysql
    DB_HOST=127.0.0.1
    DB_PORT=3306
    DB_DATABASE=your_database
    DB_USERNAME=your_user
    DB_PASSWORD=your_pass
    ```

5. Prepare the database

## Getting started — Installation flows

Choose one of the flows below depending on whether you want to run the project locally, inside Docker using Laravel Sail, or using a generic Docker Compose setup. These flows are short, actionable steps intended as a quick start; full instructions appear in the sections below.

A) Local development (fastest for iteration)

-   Clone the repository and install PHP dependencies:

    ```bash
    git clone <repo-url> sd_container_256
    cd sd_container_256
    composer install
    ```

-   Install JS dependencies and run dev server (Vite):

-   Frontend assets (optional):

    ```bash
    # Only necessary if you plan to modify or build frontend assets
    # npm install
    # npm run dev
    ```

-   Copy `.env`, generate a key, migrate and link storage:

    ```bash
    cp .env.example .env
    php artisan key:generate
    php artisan migrate --seed
    php artisan storage:link
    php artisan serve
    ```

B) Docker with Laravel Sail (recommended for consistent dev env)

-   Short steps (see "Docker / docker-compose" section for full commands):

    ```bash
    cp .env.example .env
    composer install
    php artisan sail:install   # choose services when prompted
    ./vendor/bin/sail up -d
    ./vendor/bin/sail php artisan key:generate
    ./vendor/bin/sail php artisan migrate --seed
    ./vendor/bin/sail php artisan storage:link
    ```

### Initial credentials

If you want to create a new admin user after running migrations, use `php artisan tinker` and run (example):

```php
$user = \App\Models\User::where('email', 'admin@example.com')->first();
$user->password = bcrypt('your-new-password');
$user->save();
```

Or create a new user from tinker:

```php
\App\Models\User::create([
	'name' => 'Admin',
	'email' => 'admin@example.com',
	'password' => bcrypt('secret'),
]);
```

C) Generic Docker Compose

-   If you maintain your own `docker-compose.yml`, use these generic steps:

    ```bash
    docker-compose up -d
    docker compose exec app sh
    composer install
    cp .env.example .env
    php artisan key:generate
    php artisan migrate --seed
    exit
    ```

Notes:

-   For a very fast local test you can switch to SQLite: set `DB_CONNECTION=sqlite` and create `database/database.sqlite`.
-   Never commit `.env` to the repository; keep secrets out of source control.

    ```zsh
    php artisan migrate --seed
    ```

    Note: The repository contains migrations such as `create_albums_table`, `add_images_to_albums_table`, and later updates (e.g., adding comments, loras). If you prefer sqlite for local quick-run, set `DB_CONNECTION=sqlite` and create `database/database.sqlite`.

6.  Link storage (for local filesystem disk)

    ```zsh
    php artisan storage:link
    ```

7.  Build assets (or run Vite dev) — optional

        - Development (hot reload):

        	```zsh
        	# npm run dev  # optional
        	```

        - Production build:

        	```zsh
        	# npm run build  # optional
        	```

8.  Serve the app

    ```zsh
    php artisan serve
    # then visit http://127.0.0.1:8000
    ```

## Docker / docker-compose

This project includes a `docker-compose.yml`. If you'd rather use Docker:

1. Start services

    ```zsh
    docker-compose up -d
    ```

2. Exec into the app container and install deps / run migrations (adjust container name as needed)

    ```zsh
    docker compose exec app sh
    composer install
    cp .env.example .env
    php artisan key:generate
    php artisan migrate --seed
    exit
    ```

3. Visit the exposed HTTP port configured in `docker-compose.yml`.

## Environment variables

Key env vars to check/adjust:

-   `APP_URL`, `APP_ENV`, `APP_DEBUG` — app settings
-   `DB_*` — database connection
-   `FILESYSTEM_DRIVER` — `local` or `s3` (if using AWS S3 via Flysystem)
-   `AWS_*` — AWS credentials and region (if using S3)

There is also an `image.php` config file (see `config/image.php`) which controls image-related settings used by `ImageService`.

## Docker Compose — quick copy

If you want a short copy of the docker-compose steps to paste into a server setup script, use:

```bash
# Start containers
docker-compose up -d

# Enter the app container
docker compose exec app sh

# Inside container: install PHP deps, set env and run migrations
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
exit
```

## S3 & filesystem (detailed)

This project supports S3-compatible storage (e.g., DigitalOcean Spaces) and a local fallback. Important env vars:

```env
FILESYSTEM_DRIVER=s3
AWS_ACCESS_KEY_ID="your-digitalocean-access-key"
AWS_SECRET_ACCESS_KEY="your-digitalocean-secret-key"
AWS_DEFAULT_REGION=nyc3
AWS_ENDPOINT=https://nyc3.digitaloceanspaces.com
AWS_BUCKET=your-bucket-name
AWS_UPLOAD_FOLDER=your-folder-name  # optional prefix used by upload logic
```

Filesystem config (example `config/filesystems.php` disk):

```php
's3' => [
	'driver' => 's3',
	'key'    => env('AWS_ACCESS_KEY_ID'),
	'secret' => env('AWS_SECRET_ACCESS_KEY'),
	'region' => env('AWS_DEFAULT_REGION'),
	'bucket' => env('AWS_BUCKET'),
	'endpoint' => env('AWS_ENDPOINT'),
	'use_path_style_endpoint' => false,
],
```

Usage examples in the app (Laravel Storage):

```php
// store a file
Storage::disk('s3')->put($path, $contents, 'public');

// get a public URL (works with endpoint + CDN)
$url = Storage::disk('s3')->url($path);
```

Notes:

-   If you use a upload folder prefix (`AWS_UPLOAD_FOLDER`), prepend it to stored paths when saving and retrieving.
-   For local development, set `FILESYSTEM_DRIVER=local` and run `php artisan storage:link` to serve files from `storage/app/public`.
-   Ensure your DigitalOcean Spaces/CORS and permissions allow public read if you use direct URLs.

## Database migrations & seeding

Run migrations and (optionally) seeders:

```zsh
php artisan migrate
php artisan db:seed
```

If you need to refresh the DB during development:

```zsh
php artisan migrate:fresh --seed
```

## Assets (Vite) — optional

This project can use Vite for asset bundling. Frontend steps are optional — the application will run without building assets. If you plan to work on front-end code or want to rebuild assets, install Node/npm and run:

```bash
# npm install
# npm run dev   # during development (HMR)
# npm run build # for production
```

<!-- Unit tests are available but not required for running the application. -->

## Troubleshooting

-   Permissions: ensure the web server and PHP can write to `storage/` and `bootstrap/cache`:

    ```zsh
    chmod -R 775 storage bootstrap/cache
    chown -R $USER:www-data storage bootstrap/cache
    ```

-   Composer memory limit: if composer fails on low-memory containers, run:

    ```zsh
    COMPOSER_MEMORY_LIMIT=-1 composer install
    ```

-   If images are not showing, verify `FILESYSTEM_DRIVER` and `php artisan storage:link`.

## Project structure highlights

-   `app/Models` — Eloquent models (`Album`, `User`, ...)
-   `app/Observers` — model observers (e.g., `AlbumObserver`)
-   `app/Services` — app services (image processing, metadata handling)
-   `app/Filament/Resources` — Filament admin resources for managing models
-   `database/migrations` — DB migrations including albums, images, loras
-   `routes/web.php` — web routes

Explore these folders to learn how albums and images are created, processed, and stored.

---

## Encrypted image workflow (upload, metadata, streaming preview)

This project includes a full encrypted-image pipeline designed to ensure plaintext image bytes are never written to disk or stored unencrypted in object storage. The detailed diagram and timings are available in `WORKFLOW.md` — the bullet points below summarise the implementation and developer-facing behaviors.

### High level

-   Uploads happen via the Filament resource (`app/Filament/Resources/AlbumResource.php`).
-   Plaintext bytes are read in memory, metadata (if any) is extracted and stored temporarily in session, a thumbnail is generated, and both image and thumbnail are encrypted in-memory with Laravel `Crypt::encryptString()` before being uploaded to the configured filesystem (S3 or local).
-   Albums store image paths in the database (e.g. `albums/{albumId}/{filename}`) and use Eloquent mutators/accessors to keep album fields encrypted/decrypted when persisted or read.
-   After the DB record is created, `ImageService::ensureImagesProcessed()` moves files from the base `albums/` folder into album-specific subfolders (`albums/{id}/...`) and updates the DB paths.
-   When a browser requests an image, the controller (`app/Http/Controllers/ImageController.php`) fetches the encrypted object from the storage disk, decrypts in-memory with `Crypt::decryptString()`, and streams the plaintext bytes to the response without saving them to disk or container storage.

### Key components and responsibilities

-   `AlbumResource::saveUploadedFileUsing()`

    -   Reads plaintext into memory from the Livewire temporary upload.
    -   Calls `MetaDataService::extractMetadataFromContent()` (PNG tEXt chunk parsing / ComfyUI prompt extraction).
    -   Stores extracted metadata in session under `image_metadata_{filename}`.
    -   Generates a 200×200 JPEG thumbnail using Intervention Image when `config('image_encrypt.encrypt_thumbnails')` is enabled.
    -   Encrypts thumbnail and image with `Crypt::encryptString()` and uploads directly to the storage disk (S3/local) — plaintext never leaves memory.

-   `MetaDataService`

    -   `extractMetadataFromContent($bytes)` parses PNG chunks for prompt/workflow metadata and returns a structured array (or null for JPEGs).
    -   `extractAndSaveMetadata($album)` is called after create: it reads the session metadata for the first image and applies these fields to the `Album` model (stored in DB), then clears the session key.

-   `Multi-image metadata support`

    -   The metadata pipeline supports extracting metadata from multiple uploaded images in a single album. During upload `MetaDataService::extractMetadataFromContent()` stores per-file session keys (e.g. `image_metadata_{filename}`) and `MetaDataService::extractAndSaveMetadata($album)` can aggregate or apply metadata from all images in the album (for example: merging tags, picking a dominant prompt, or storing per-image metadata entries in an album JSON column). This enables richer album-level metadata derived from several image files rather than assuming a single-source image.

-   `ImageService::ensureImagesProcessed($album)`

    -   Moves files uploaded to the base `albums/` and `albums/thumbnails/` folders into `albums/{albumId}/` and `albums/{albumId}/thumbnails/` respectively using the storage disk `move()` calls.
    -   Updates the album model images paths and persists the model.

-   `ImageController::showImage()` and `showThumbnail()`

    -   Validate album and filename, build the storage path, `Storage::disk(...)->get($path)` the encrypted blob, `Crypt::decryptString()` in-memory, and return a streamed response with the correct MIME type.
    -   On decryption failure (tampered data or wrong key), return 403.

-   `SearchCacheService`

    -   Provides lightweight search indexing and caching for album and image metadata to accelerate search queries and filter results. The service is responsible for building and maintaining a searchable cache of album fields (prompts, tags, loras, dates, etc.) and is updated by observers or after-image-processing hooks. This reduces DB load for common listing and search operations and can be adapted to backends like Redis, an in-memory map, or a full-text index depending on deployment needs.

### Album fields in database (mutators & accessors)

The album model stores generation fields (prompt, negative, seed, steps, sampler, cfg, loras, etc.) on the database record. Implementation notes:

-   Use Eloquent mutators/accessors (or model $casts) to encrypt sensitive fields at rest in the DB (if desired) and to present friendly types when reading.
-   Example design (conceptual):
    -   Mutator: setPositiveAttribute($value) { $this->attributes['positive'] = encryptor($value); }
    -   Accessor: getPositiveAttribute($value) { return decryptor($value); }
    -   Prefer Laravel's built-in encrypted casts or a shared trait for consistency across fields.

Note: the codebase already contains fields and migrations for many of these columns — check `app/Models/Album.php` and `database/migrations/*create_albums_table.php` for column names.

### Session-based metadata lifecycle

-   `image_metadata_{filename}` session keys are written during upload and read once after album creation. The metadata lifetime is very short (in-memory session until `afterCreate()` runs) and is explicitly cleared after consumption.

### Config & environment

-   Ensure `APP_KEY` is set (used by Laravel `Crypt`).
-   `FILESYSTEM_DRIVER` controls storage (e.g., `s3` or `local`).
-   If using S3-compatible storage, configure `AWS_*` and optional `AWS_UPLOAD_FOLDER`. The upload logic prepends `AWS_UPLOAD_FOLDER` when building paths.
-   `config/image.php` exposes `encrypt_thumbnails` and other image settings. Check and update as needed.

### Security considerations

-   Plaintext image bytes are never stored on disk or uploaded to object storage unencrypted.
-   Encryption is performed immediately in memory; uploaded objects are encrypted blobs which cannot be read without application `APP_KEY`.
-   Streaming preview decrypts in-memory then streams to the response; the container filesystem is not used for decrypted content.

### Routes / Preview URLs

-   Filament and the album UI will build preview URLs like:

    -   `/albums/image/{albumId}/{filename}`
    -   `/albums/thumbnail/{albumId}/{filename}`

-   These routes are handled by `ImageController` which performs in-memory decryption and streaming.

### Developer tips and next steps

-   If you need to process many images in background, consider queuing `ensureImagesProcessed()` and metadata application to avoid long web requests.
-   To add additional metadata extraction (other file formats), extend `MetaDataService::extractMetadataFromContent()`.
-   Review `WORKFLOW.md` in the repository for the full diagram, timings, and the end-to-end flow.

### Where to look in the codebase

-   `app/Filament/Resources/AlbumResource.php` — upload hooks and save handlers
-   `app/Services/ImageService.php` — move/organize files and thumbnail handling
-   `app/Services/MetaDataService.php` — image metadata extraction & application (also supports multi-image metadata aggregation and per-file session keys)
-   `app/Http/Controllers/ImageController.php` — streaming preview endpoints
-   `app/Models/Album.php` — mutators/accessors and album fields
-   `app/Services/SearchCacheService.php` — search indexing and cache for album/image metadata (keeps search fast and reduces DB load)

## Contributing

Contributions are welcome. Please open issues or pull requests with focused changes. For larger changes, open an issue first to discuss design and impact.

## License

This project follows the MIT license (inherited from the Laravel skeleton). Check the LICENSE file or `composer.json` for details.

## Quick reference commands

```zsh
# install php deps
composer install

# set env and generate key
cp .env.example .env
php artisan key:generate

# run migrations and seed
php artisan migrate --seed

# link storage
php artisan storage:link

# dev server
php artisan serve
```
