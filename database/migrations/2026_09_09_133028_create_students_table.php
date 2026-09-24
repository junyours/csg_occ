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
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('student_number')->unique();
            $table->string('last_name');
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('suffix')->nullable();
            $table->foreignId('department_id')->constrained();
            $table->string('year_level')->nullable();
            $table->string('section')->nullable();

            $table->string('role')->default('student'); // enum-backed, cast in the Model
            $table->foreignId('sc_admin_department_id')->nullable()->constrained('departments');

            $table->string('photo_path')->nullable();

            $table->text('qr_token')->nullable();
            $table->unsignedInteger('qr_version')->default(1);

            $table->string('username')->unique();
            $table->string('password');
            $table->boolean('must_change_password')->default(true);

            $table->rememberToken();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
