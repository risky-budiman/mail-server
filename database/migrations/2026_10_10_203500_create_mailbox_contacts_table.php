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
        Schema::create('mailbox_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('virtual_user_id')->constrained('virtual_users')->onDelete('cascade');
            $table->string('name')->nullable();
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('company')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('last_communicated_at')->nullable();
            $table->unsignedInteger('communication_count')->default(1);
            $table->timestamps();

            $table->unique(['virtual_user_id', 'email']);
            $table->index(['virtual_user_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mailbox_contacts');
    }
};
