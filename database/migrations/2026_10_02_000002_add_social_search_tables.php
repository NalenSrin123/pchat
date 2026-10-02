<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('query', 100);
            $table->timestamps();
            $table->unique(['user_id', 'query']);
            $table->index(['user_id', 'updated_at']);
        });
        Schema::create('dismissed_friend_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('suggested_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'suggested_user_id']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->index(['status', 'name']);
            $table->index(['status', 'username']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['status', 'name']);
            $table->dropIndex(['status', 'username']);
        });
        Schema::dropIfExists('dismissed_friend_suggestions');
        Schema::dropIfExists('search_histories');
    }
};
