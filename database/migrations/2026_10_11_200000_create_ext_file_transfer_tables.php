<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ext_file_transfer_transfers', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('type', 8);
            $table->string('status', 12)->index();
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('source_server_id')->nullable();
            $table->unsignedInteger('target_server_id')->nullable();
            // Protocol, host, port, user and folder of the remote side. Never secrets.
            $table->json('remote')->nullable();
            // Password or key, encrypted, only while the transfer runs.
            $table->text('credentials')->nullable();
            $table->text('source_root');
            $table->json('selection');
            $table->text('target_root');
            $table->json('options');
            $table->string('archive', 255)->nullable();
            $table->json('cursor')->nullable();
            $table->unsignedInteger('files_total')->default(0);
            $table->unsignedInteger('files_ok')->default(0);
            $table->unsignedInteger('files_failed')->default(0);
            $table->unsignedInteger('files_skipped')->default(0);
            $table->unsignedBigInteger('bytes_total')->default(0);
            $table->unsignedBigInteger('bytes_done')->default(0);
            $table->text('current_path')->nullable();
            $table->string('error', 500)->nullable();
            $table->unsignedTinyInteger('crashes')->default(0);
            // Only the job chain with this token may work on the transfer.
            $table->string('chain', 16)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('source_server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->foreign('target_server_id')->references('id')->on('servers')->cascadeOnDelete();
        });

        // The work list of a transfer. Finished transfers only keep failed and skipped entries.
        Schema::create('ext_file_transfer_items', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('transfer_id');
            $table->text('path');
            $table->boolean('directory')->default(false);
            $table->unsignedBigInteger('size')->default(0);
            $table->string('status', 8)->default('pending');
            $table->string('message', 255)->nullable();

            $table->index(['transfer_id', 'status']);
            $table->foreign('transfer_id')->references('id')->on('ext_file_transfer_transfers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_file_transfer_items');
        Schema::dropIfExists('ext_file_transfer_transfers');
    }
};
