<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailboxContact extends Model
{
    protected $table = 'mailbox_contacts';

    protected $fillable = [
        'virtual_user_id',
        'name',
        'email',
        'phone',
        'company',
        'notes',
        'last_communicated_at',
        'communication_count',
    ];

    protected $casts = [
        'last_communicated_at' => 'datetime',
        'communication_count' => 'integer',
    ];

    public function virtualUser(): BelongsTo
    {
        return $this->belongsTo(VirtualUser::class, 'virtual_user_id');
    }

    /**
     * Catat atau perbarui kontak saat terjadi komunikasi (masuk atau keluar).
     */
    public static function recordCommunication(int $virtualUserId, ?string $rawEmail, ?string $rawName = null, $timestamp = null): ?self
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('mailbox_contacts')) {
                return null;
            }

            if (empty($rawEmail)) {
                return null;
            }

            // Ekstrak email murni jika memuat format "Nama <email@domain.com>"
            $email = trim($rawEmail);
            $name = trim($rawName ?? '');

            if (preg_match('/^(.*?)\s*<([^>]+)>/', $rawEmail, $matches)) {
                if (empty($name)) {
                    $name = trim($matches[1], " \t\n\r\0\x0B\"'");
                }
                $email = trim($matches[2]);
            }

            $email = strtolower(trim($email));

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return null;
            }

            // Cari atau buat kontak
            $contact = self::firstOrNew([
                'virtual_user_id' => $virtualUserId,
                'email' => $email,
            ]);

            if (!empty($name) && (empty($contact->name) || $contact->name === $email)) {
                $contact->name = $name;
            }

            $contact->last_communicated_at = $timestamp ? \Carbon\Carbon::parse($timestamp) : now();

            if ($contact->exists) {
                $contact->communication_count = ($contact->communication_count ?? 1) + 1;
            } else {
                $contact->communication_count = 1;
                if (empty($contact->name)) {
                    $contact->name = explode('@', $email)[0];
                }
            }

            $contact->save();

            return $contact;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
