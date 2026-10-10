<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\MailboxEmail;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('mailbox_emails')) {
            return;
        }

        try {
            // Bersihkan duplikasi disclaimer / paragraf berulang pada email yang diimpor
            $emailsWithDuplicates = MailboxEmail::where(function ($q) {
                $q->where('body', 'like', '%DISCLAIMER:%DISCLAIMER:%')
                  ->orWhere('body', 'like', '%PERHATIAN:%PERHATIAN:%')
                  ->orWhere('body', 'like', '%CONFIDENTIALITY NOTICE:%CONFIDENTIALITY NOTICE:%');
            })->get();

            foreach ($emailsWithDuplicates as $em) {
                $deduped = preg_replace('/(\b[^\r\n<>]{40,}\b)(?:\s*(?:<[^>]+>|\r?\n|\s)+\1)+/is', '$1', $em->body);
                if ($deduped !== $em->body) {
                    $em->update(['body' => $deduped]);
                }
            }
        } catch (\Throwable $e) {
            // Migrasi tidak boleh memutus deployment
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op
    }
};
