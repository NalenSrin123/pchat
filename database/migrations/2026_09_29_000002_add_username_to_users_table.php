<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 30)->nullable()->unique()->after('name');
        });

        DB::table('users')->orderBy('id')->each(function (object $user): void {
            $base = Str::of((string) $user->email)->before('@')->lower()->replaceMatches('/[^a-z0-9_-]/', '_')->trim('_')->substr(0, 24)->value() ?: 'user';
            $username = $base;
            $suffix = 1;

            while (DB::table('users')->where('username', $username)->exists()) {
                $suffix++;
                $username = Str::substr($base, 0, 30 - strlen((string) $suffix) - 1).'-'.$suffix;
            }

            DB::table('users')->where('id', $user->id)->update(['username' => $username]);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
