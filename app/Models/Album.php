<?php

namespace App\Models;

use App\Observers\AlbumObserver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Album extends Model
{
    use HasFactory;

    protected $fillable = ['prompt', 'images'];

    protected $casts = [
        'images' => 'array',
    ];

    public function getRandomImageAttribute()
    {
        $images = $this->images;
        return $images ? $images[array_rand($images)] : null;
    }


    public function getThumbnailUrlsAttribute()
    {
        $images = $this->images;

        $thumbnails = [];

        foreach ($images as $image) {
            $thumbnails[] = "albums/{$this->id}/thumbnails/" . basename($image);
        }

        return $thumbnails;
    }
}
