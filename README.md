# SB Container — Image & Album Manager (Laravel)

This repository is a Laravel-based web application that manages albums and images. It includes services for image processing and metadata extraction, Filament-based admin resources, observers for model events, and support for local filesystem or S3 storage.

Key components you will find in the codebase:

-   Albums and Image management (models, migrations, controllers)
-   `app/Services/ImageService.php` — image resizing/processing and storage operations
-   `app/Services/MetaDataService.php` — extraction and handling of image metadata
-   Observers (e.g., `app/Observers/AlbumObserver.php`) for keeping related state in sync
-   Filament admin resources under `app/Filament/Resources` for admin UI
-   Migrations that include albums, images, loras, comments and super user table additions

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
    git clone <repo-url> sb_container
    cd sb_container
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
    git clone <repo-url> sb_container
    cd sb_container
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

### Initial credentials (default seeded user)

If you run the seeders (`php artisan migrate --seed` or the Sail equivalent), the project includes a default seeded login that you can use to access the admin/UI immediately.

-   Username / Email: `dev@dev.com`
-   Password: `dev@dev.com`
    This default user is created directly by a migration (`database/migrations/2024_10_13_055124_add_super_user_table.php`) which inserts a super user with the credentials shown above (the password is hashed with `Hash::make`).

If you want to change the password or create a new admin user after running migrations, use `php artisan tinker` and run (example):

```php
$user = \App\Models\User::where('email', 'dev@dev.com')->first();
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

---

If you'd like, I can also add a short CONTRIBUTING.md, a docker-compose usage section with exact container names, or examples of using the Filament admin UI (routes/credentials). Tell me which extra details you want next.
