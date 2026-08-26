<?php

namespace App\Models;

use App\Models\Concerns\SynchronizesLocalizedSingleton;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SiteNavigation extends Model
{
    use SynchronizesLocalizedSingleton;

    public function translations(): HasMany
    {
        return $this->hasMany(SiteNavigationTranslation::class);
    }

    protected $fillable = ['configuration', 'center_mega_menu_promo_image_path', 'medical_areas_mega_menu_promo_image_path'];

    protected function casts(): array
    {
        return ['configuration' => 'array'];
    }
}
