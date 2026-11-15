<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('youtube_account_id')->nullable()->constrained()->nullOnDelete();

            // Generation input
            $table->string('topic')->nullable();
            $table->text('angle')->nullable();

            // Generation output
            $table->longText('script')->nullable();
            $table->json('scenes')->nullable();      // [{narration, visual_query, asset_path, asset_source, asset_licence}]
            $table->json('chapters')->nullable();    // [{time, title}]
            $table->string('audio_path')->nullable();
            $table->string('captions_path')->nullable();
            $table->longText('render_log')->nullable();
            $table->timestamp('approved_at')->nullable();

            // Upload metadata
            $table->string('file_path')->nullable();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->json('tags')->nullable();
            $table->string('category_id')->default('27');
            $table->boolean('is_short')->default(false);
            $table->boolean('contains_synthetic_media')->default(true);
            $table->string('thumbnail_text')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->timestamp('publish_at')->nullable();

            // Pipeline state
            $table->string('status')->default('draft')->index();
            $table->string('youtube_video_id')->nullable()->unique();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'publish_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};
