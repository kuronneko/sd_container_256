<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Album extends Model
{
    use HasFactory;

    protected $fillable = ['text', 'images'];

    protected $casts = [
        'images' => 'array',
    ];

        /**
     * Define a relationship with the Imagen model.
     */
/*     public function imagenes(): MorphMany
    {
        return $this->morphMany(Imagen::class, 'imageable');
    } */
}
