<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fixes "a 5-minute code shows 476 minutes left".
     *
     * `expires_at` was created as a NOT NULL `timestamp`. On MySQL/MariaDB the
     * first NOT NULL timestamp column in a table is silently given
     * "DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP". So every UPDATE
     * of a code row (a wrong guess bumping `attempts`, for example) overwrote
     * expires_at with "now" — written in the database session's timezone
     * (Asia/Manila, +8h) but read back by Laravel as UTC. The code's expiry
     * jumped ~8 hours into the future.
     *
     * A DATETIME column has no such magic, and Laravel stores/reads it in the
     * app timezone (UTC) consistently.
     */
    public function up(): void
    {
        // Scratch data that lives for minutes. Existing rows may already carry
        // a corrupted expiry, and changing the column type would reinterpret
        // the rest, so start clean. Anyone mid-reset simply requests a new code.
        DB::table('password_reset_codes')->delete();

        Schema::table('password_reset_codes', function (Blueprint $table) {
            $table->dateTime('expires_at')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        DB::table('password_reset_codes')->delete();

        Schema::table('password_reset_codes', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable(false)->change();
        });
    }
};
