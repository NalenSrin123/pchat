<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // Existing installs used an enum. Voice messages need a new, explicit type.
            $table->string('type', 20)->default('text')->change();
            $table->unsignedInteger('audio_duration')->nullable()->after('file_mime_type');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('audio_duration');
            $table->enum('type', ['text', 'image', 'file'])->default('text')->change();
        });
    }
};
