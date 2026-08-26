<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiteNavigation extends Model
{
    protected $fillable = ['configuration', 'center_mega_menu_promo_image_path', 'medical_areas_mega_menu_promo_image_path'];

    protected function casts(): array
    {
        return ['configuration' => 'array'];
    }
}
