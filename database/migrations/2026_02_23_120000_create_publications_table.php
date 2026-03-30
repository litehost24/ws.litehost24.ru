<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publications', function (Blueprint $table): void {
            $table->id();
            $table->string('audience', 20); // active|inactive
            $table->string('subject');
            $table->text('body');
            $table->string('status', 20)->default('draft'); // draft|sending|sent|failed|partial
            $table->unsignedInteger('snapshot_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->timestamps();

            $table->index(['audience', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publications');
    }
};
