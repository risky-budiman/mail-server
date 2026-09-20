<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailboxEmail extends Model
{
    protected $table = 'mailbox_emails';

    protected $fillable = [
        'virtual_user_id',
        'folder',
        'from_name',
        'from_email',
        'to',
        'subject',
        'date_human',
        'is_read',
        'is_starred',
        'body',
        'attachments',
        'spam_reason',
        'spam_score',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'is_starred' => 'boolean',
        'attachments' => 'array',
        'spam_score' => 'float',
    ];

    public function virtualUser(): BelongsTo
    {
        return $this->belongsTo(VirtualUser::class, 'virtual_user_id');
    }
}
