<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('mailbox_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('virtual_user_id')->constrained('virtual_users')->onDelete('cascade');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('mailbox_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('virtual_user_id')->constrained('virtual_users')->onDelete('cascade');
            $table->string('folder')->default('inbox'); // inbox, sent, drafts, spam, trash, or custom
            $table->string('from_name')->nullable();
            $table->string('from_email');
            $table->string('to');
            $table->string('subject')->nullable();
            $table->string('date_human')->nullable();
            $table->boolean('is_read')->default(false);
            $table->boolean('is_starred')->default(false);
            $table->text('body')->nullable();
            $table->json('attachments')->nullable();
            $table->string('spam_reason')->nullable();
            $table->float('spam_score')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mailbox_emails');
        Schema::dropIfExists('mailbox_folders');
    }
};
