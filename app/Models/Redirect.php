<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Redirect extends Model
{
    use HasFactory;

    public const SOURCE_TYPE_PAGE = 'page';

    public const SOURCE_TYPE_EQUIPE_PROFILE = 'equipe_profile';

    public const SOURCE_TYPE_MEDICAL_AREA = 'medical_area';

    public const SOURCE_TYPE_SERVICE_WEB_PROFILE = 'service_web_profile';

    public const SOURCE_TYPE_CHECKUP_WEB_PROFILE = 'checkup_web_profile';

    protected $fillable = [
        'legacy_backend_id',
        'from_path',
        'to_path',
        'http_code',
        'is_active',
        'is_automatic',
        'source_type',
        'source_id',
    ];

    protected function casts(): array
    {
        return [
            'legacy_backend_id' => 'integer',
            'http_code' => 'integer',
            'is_active' => 'boolean',
            'is_automatic' => 'boolean',
            'source_id' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeAutomatic(Builder $query): Builder
    {
        return $query->where('is_automatic', true);
    }

    protected function fromPath(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => self::normalizePathValue($value),
        );
    }

    protected function toPath(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => self::normalizeTargetValue($value),
        );
    }

    public static function normalizePathValue(string $value): string
    {
        $path = trim($value);

        if ($path === '' || $path === '/') {
            return '/';
        }

        return '/'.ltrim($path, '/');
    }

    public static function normalizeTargetValue(string $value): string
    {
        $target = trim($value);

        if ($target === '') {
            return '/';
        }

        if (filter_var($target, FILTER_VALIDATE_URL)) {
            return $target;
        }

        return self::normalizePathValue($target);
    }
}
