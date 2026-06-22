<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OldCoreImportMapping extends Model
{
    use HasFactory;

    protected $fillable = [
        'entity_type',
        'old_table',
        'old_id',
        'new_table',
        'new_id',
        'source_hash',
        'source_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'old_id' => 'integer',
            'new_id' => 'integer',
            'source_updated_at' => 'datetime',
        ];
    }
}
