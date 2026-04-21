<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceAlias extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_id',
        'alias_name',
        'alias_slug',
        'source_label',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
