<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('content')->nullable();
            $table->string('media_path')->nullable();
            $table->string('media_disk', 40)->default('local');
            $table->string('media_type', 10)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->string('privacy', 20)->default('friends');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['expires_at', 'created_at']);
            $table->index(['user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stories');
    }
};
