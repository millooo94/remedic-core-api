<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Redirect extends Model
{
    use HasFactory;

    protected $fillable = [
        'legacy_backend_id',
        'from_path',
        'to_path',
        'http_code',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'legacy_backend_id' => 'integer',
            'http_code' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    protected function fromPath(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => $this->normalizePath($value),
        );
    }

    protected function toPath(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => $this->normalizeTarget($value),
        );
    }

    protected function normalizePath(string $value): string
    {
        $path = trim($value);

        if ($path === '' || $path === '/') {
            return '/';
        }

        return '/'.ltrim($path, '/');
    }

    protected function normalizeTarget(string $value): string
    {
        $target = trim($value);

        if ($target === '') {
            return '/';
        }

        if (filter_var($target, FILTER_VALIDATE_URL)) {
            return $target;
        }

        return $this->normalizePath($target);
    }
}
