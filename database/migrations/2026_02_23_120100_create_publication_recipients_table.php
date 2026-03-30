<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publication_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('publication_id')->constrained('publications')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email');
            $table->string('status', 20)->default('pending'); // pending|sent|failed|skipped
            $table->text('error_text')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['publication_id', 'email']);
            $table->index(['publication_id', 'status']);
            $table->index(['email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publication_recipients');
    }
};
