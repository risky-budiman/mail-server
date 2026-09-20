<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailboxFolder extends Model
{
    protected $table = 'mailbox_folders';

    protected $fillable = [
        'virtual_user_id',
        'name',
    ];

    public function virtualUser(): BelongsTo
    {
        return $this->belongsTo(VirtualUser::class, 'virtual_user_id');
    }
}
