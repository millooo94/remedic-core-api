<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ConsentConfiguration extends Model
{
    use HasFactory;

    protected $fillable = ['is_enabled', 'configuration_version'];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'configuration_version' => 'integer'];
    }
}
