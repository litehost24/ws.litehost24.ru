<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publication_test_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('audience', 20); // active|inactive
            $table->string('subject');
            $table->string('to_email');
            $table->string('status', 20); // sent|failed
            $table->text('error_text')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['audience', 'created_at']);
            $table->index(['to_email', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publication_test_logs');
    }
};
