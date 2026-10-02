<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['story_id', 'user_id']);
        });
        Schema::create('story_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reaction', 12);
            $table->timestamps();
            $table->unique(['story_id', 'user_id']);
        });
        Schema::table('messages', fn (Blueprint $table) => $table->foreignId('story_id')->nullable()->after('reply_to_id')->constrained()->nullOnDelete());
    }

    public function down(): void
    {
        Schema::table('messages', fn (Blueprint $table) => $table->dropConstrainedForeignId('story_id'));
        Schema::dropIfExists('story_reactions');
        Schema::dropIfExists('story_views');
    }
};
