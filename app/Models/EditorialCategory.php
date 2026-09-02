<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EditorialCategory extends Model
{
    protected $fillable = ['content_type', 'name'];

    public function posts(): HasMany
    {
        return $this->hasMany(BlogPost::class);
    }
}
