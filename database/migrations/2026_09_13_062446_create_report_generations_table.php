<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backs the roster report's async generation flow: a full-school PDF
     * (every department/year/section, no filters) can genuinely take
     * long enough to render that it used to just hang or time out inside
     * a single request — see DompdfRosterPdfRenderer's docblock. Instead
     * of a queue worker (explicitly ruled out — shared hosting has no
     * process to run `php artisan queue:work`), a row here is created
     * synchronously and near-instantly, then the actual build+render
     * work runs via dispatch(...)->afterResponse() in the same PHP-FPM
     * worker after the HTTP response has already been flushed to the
     * browser. The frontend polls this row's processed_steps/total_steps
     * for a real, DB-backed progress bar — not a fake timer.
     */
    public function up(): void
    {
        Schema::create('report_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events');
            $table->string('format'); // xlsx|pdf
            $table->foreignId('department_id')->nullable()->constrained('departments');
            $table->string('year_level')->nullable();
            $table->string('section')->nullable();
            $table->string('status')->default('pending'); // pending|processing|completed|failed
            $table->unsignedInteger('total_steps')->default(0);
            $table->unsignedInteger('processed_steps')->default(0);
            $table->string('file_disk')->default('local');
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('requested_by')->constrained('students');
            $table->timestamps();

            $table->index(['requested_by', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_generations');
    }
};
