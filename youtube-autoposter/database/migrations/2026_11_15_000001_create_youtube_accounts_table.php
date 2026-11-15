<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('youtube_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('channel_id')->unique();
            $table->string('channel_title')->nullable();
            $table->text('refresh_token'); // encrypted cast
            $table->string('status')->default('connected'); // connected | disconnected
            $table->string('last_error')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('youtube_accounts');
    }
};
