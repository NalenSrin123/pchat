<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('cover_photo')->nullable()->after('avatar');
        });
        Schema::create('friendships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('receiver_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->timestamps();
            $table->unique(['sender_id', 'receiver_id']);
            $table->index(['receiver_id', 'status']);
            $table->index(['sender_id', 'status']);
        });
        Schema::create('posts', function (Blueprint $table) {
            $table->id(); $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shared_post_id')->nullable()->constrained('posts')->nullOnDelete();
            $table->text('content')->nullable(); $table->string('privacy', 20)->default('friends');
            $table->string('status', 20)->default('active'); $table->unsignedInteger('shares_count')->default(0);
            $table->softDeletes(); $table->timestamps();
            $table->index(['user_id', 'created_at']); $table->index(['privacy', 'status', 'created_at']);
        });
        Schema::create('post_media', function (Blueprint $table) {
            $table->id(); $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10); $table->string('path'); $table->string('disk', 40)->default('public');
            $table->string('mime_type', 100); $table->unsignedBigInteger('file_size');
            $table->unsignedInteger('width')->nullable(); $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration')->nullable(); $table->unsignedSmallInteger('sort_order')->default(0); $table->timestamps();
            $table->index(['post_id', 'sort_order']);
        });
        Schema::create('comments', function (Blueprint $table) {
            $table->id(); $table->foreignId('post_id')->constrained()->cascadeOnDelete(); $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('comments')->cascadeOnDelete(); $table->text('content'); $table->softDeletes(); $table->timestamps();
            $table->index(['post_id', 'parent_id', 'created_at']); $table->index(['user_id', 'created_at']);
        });
        Schema::create('post_reactions', function (Blueprint $table) {
            $table->id(); $table->foreignId('post_id')->constrained()->cascadeOnDelete(); $table->foreignId('user_id')->constrained()->cascadeOnDelete(); $table->string('reaction', 12); $table->timestamps();
            $table->unique(['post_id', 'user_id']); $table->index(['post_id', 'reaction']);
        });
        Schema::create('comment_reactions', function (Blueprint $table) {
            $table->id(); $table->foreignId('comment_id')->constrained()->cascadeOnDelete(); $table->foreignId('user_id')->constrained()->cascadeOnDelete(); $table->string('reaction', 12); $table->timestamps();
            $table->unique(['comment_id', 'user_id']);
        });
        Schema::create('saved_posts', function (Blueprint $table) {
            $table->id(); $table->foreignId('user_id')->constrained()->cascadeOnDelete(); $table->foreignId('post_id')->constrained()->cascadeOnDelete(); $table->timestamps(); $table->unique(['user_id', 'post_id']);
        });
        Schema::create('social_reports', function (Blueprint $table) {
            $table->id(); $table->morphs('reportable'); $table->foreignId('reported_by')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 40); $table->text('description')->nullable(); $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamp('reviewed_at')->nullable(); $table->timestamps();
            $table->unique(['reportable_type', 'reportable_id', 'reported_by']); $table->index(['status', 'created_at']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('social_reports'); Schema::dropIfExists('saved_posts'); Schema::dropIfExists('comment_reactions'); Schema::dropIfExists('post_reactions'); Schema::dropIfExists('comments'); Schema::dropIfExists('post_media'); Schema::dropIfExists('posts'); Schema::dropIfExists('friendships');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('cover_photo'));
    }
};
