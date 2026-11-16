<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tutorial_videos', function (Blueprint $t) {
            $t->string('caption_path')->nullable();
            $t->string('caption_language', 10)->nullable();
            $t->text('transcript')->nullable();
            $t->string('release_version', 80)->nullable();
            $t->date('reviewed_on')->nullable();
            $t->boolean('superseded')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('tutorial_videos', fn (Blueprint $t) => $t->dropColumn(['caption_path', 'caption_language', 'transcript', 'release_version', 'reviewed_on', 'superseded']));
    }
};
