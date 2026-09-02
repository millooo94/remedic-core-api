<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsentConfigurationVersion extends Model
{
    protected $fillable = ['configuration_version', 'snapshot', 'published_at'];

    protected function casts(): array
    {
        return ['configuration_version' => 'integer', 'snapshot' => 'array', 'published_at' => 'datetime'];
    }
}
