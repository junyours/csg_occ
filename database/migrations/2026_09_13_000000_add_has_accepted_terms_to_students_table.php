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
        Schema::table('students', function (Blueprint $table) {
            // First-login gate for the Terms & Conditions / Privacy Policy
            // agreement, mirrored on must_change_password: false until the
            // student explicitly accepts, then never asked again.
            $table->boolean('has_accepted_terms')->default(false)->after('must_change_password');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn('has_accepted_terms');
        });
    }
};
