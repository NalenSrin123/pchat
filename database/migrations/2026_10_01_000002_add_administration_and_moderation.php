<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('user')->index()->after('password');
            $table->string('status', 20)->default('active')->index()->after('role');
            $table->timestamp('suspended_until')->nullable()->after('status');
            $table->text('ban_reason')->nullable()->after('suspended_until');
            $table->index(['status', 'last_seen_at']);
        });
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('status', 20)->default('active')->index()->after('type');
            $table->text('moderation_reason')->nullable()->after('status');
        });
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('deleted_by')->nullable()->after('deleted_at')->constrained('users')->nullOnDelete();
            $table->text('deletion_reason')->nullable()->after('deleted_by');
        });
        Schema::create('user_moderation_actions', function (Blueprint $table) {
            $table->id(); $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admin_id')->constrained('users')->cascadeOnDelete();
            $table->string('action', 30); $table->text('reason')->nullable(); $table->timestamp('expires_at')->nullable(); $table->timestamps();
            $table->index(['user_id', 'created_at']); $table->index('action');
        });
        Schema::create('message_reports', function (Blueprint $table) {
            $table->id(); $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reported_by')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 40); $table->text('description')->nullable(); $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamp('reviewed_at')->nullable(); $table->timestamps();
            $table->unique(['message_id', 'reported_by']); $table->index(['status', 'created_at']); $table->index('reported_by');
        });
        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id(); $table->foreignId('admin_id')->constrained('users')->cascadeOnDelete();
            $table->string('action', 50); $table->string('target_type', 80)->nullable(); $table->unsignedBigInteger('target_id')->nullable();
            $table->text('reason')->nullable(); $table->json('metadata')->nullable(); $table->string('ip_address', 45)->nullable(); $table->timestamp('created_at')->useCurrent();
            $table->index(['action', 'created_at']); $table->index(['target_type', 'target_id']);
        });
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id(); $table->string('key')->unique(); $table->string('value'); $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('app_settings'); Schema::dropIfExists('admin_audit_logs'); Schema::dropIfExists('message_reports'); Schema::dropIfExists('user_moderation_actions');
        Schema::table('messages', fn (Blueprint $table) => $table->dropConstrainedForeignId('deleted_by')->dropColumn('deletion_reason'));
        Schema::table('conversations', fn (Blueprint $table) => $table->dropColumn(['status', 'moderation_reason']));
        Schema::table('users', fn (Blueprint $table) => $table->dropIndex(['role'])->dropIndex(['status'])->dropIndex(['status', 'last_seen_at'])->dropColumn(['role', 'status', 'suspended_until', 'ban_reason']));
    }
};
