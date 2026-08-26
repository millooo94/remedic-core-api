<?php

namespace App\Models;

use App\Enums\SupportedLocale;
use Illuminate\Database\Eloquent\Model;

class GlobalSeoTranslation extends Model
{
    protected $fillable = ['locale', 'default_meta_title', 'default_meta_description', 'default_social_image_path', 'publication_state'];

    protected function casts(): array
    {
        return ['locale' => SupportedLocale::class];
    }

    public function isPubliclyAvailable(): bool
    {
        return $this->publication_state === 'published' && filled($this->default_meta_title);
    }
}
