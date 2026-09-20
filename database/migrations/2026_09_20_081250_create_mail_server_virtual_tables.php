<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Skema tabel ini dirancang presisi sesuai format Postfix & Dovecot MySQL Virtual Mailbox.
     */
    public function up(): void
    {
        // 1. Virtual Domains
        Schema::create('virtual_domains', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // e.g: domain.net.id
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 2. Virtual Users / Mailboxes
        Schema::create('virtual_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained('virtual_domains')->onDelete('cascade');
            $table->string('email')->unique(); // e.g: admin@domain.net.id
            $table->string('name')->nullable(); // Display name
            $table->string('password'); // Dovecot/Postfix hash (e.g. SHA512-CRYPT / BCRYPT)
            $table->unsignedBigInteger('quota_bytes')->default(1073741824); // Default 1GB (in bytes)
            $table->unsignedBigInteger('used_bytes')->default(0);
            $table->string('maildir_path')->nullable(); // e.g. domain.net.id/admin/
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });

        // 3. Virtual Aliases (Forwarding)
        Schema::create('virtual_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained('virtual_domains')->onDelete('cascade');
            $table->string('source_email'); // e.g: contact@domain.net.id
            $table->string('destination_email'); // e.g: admin@domain.net.id or external mail
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('virtual_aliases');
        Schema::dropIfExists('virtual_users');
        Schema::dropIfExists('virtual_domains');
    }
};
