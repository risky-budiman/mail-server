<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VirtualAlias extends Model
{
    protected $fillable = [
        'domain_id',
        'source_email',
        'destination_email',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(VirtualDomain::class, 'domain_id');
    }
}
