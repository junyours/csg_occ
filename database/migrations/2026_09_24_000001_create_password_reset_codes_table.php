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
        Schema::create('password_reset_codes', function (Blueprint $table) {
            $table->id();
            // Cascade, unlike most FKs in this app: these rows are short-lived
            // scratch data (minutes, not history), so there is nothing worth
            // keeping if the student row itself is ever removed.
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();

            // The 6-digit code. Encrypted at rest (Model cast) rather than
            // hashed, because an SC/CSG admin has to be able to read it back
            // out and tell the student — a one-way hash couldn't do that.
            $table->text('code');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');

            // Set the moment the student enters the right code: the code is
            // single-use, so "used" means "spent", not "the password changed".
            $table->timestamp('used_at')->nullable();

            // Entering the right code doesn't change the password itself — it
            // trades the code for a short-lived, single-use reset token that
            // the "set new password" step must present. Only the SHA-256 of
            // the token is stored. Nulled out once the password is changed.
            $table->string('reset_token_hash', 64)->nullable();
            $table->timestamp('reset_token_expires_at')->nullable();

            $table->timestamps();

            $table->index(['student_id', 'used_at', 'expires_at']);
            $table->index('reset_token_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('password_reset_codes');
    }
};
