<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VirtualDomain extends Model
{
    protected $fillable = [
        'name',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(VirtualUser::class, 'domain_id');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(VirtualAlias::class, 'domain_id');
    }
}
