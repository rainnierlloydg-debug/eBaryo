<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_requests', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamp('admin_read_at')->nullable();
        });
        Schema::table('resident_notifications', function (Blueprint $table) {
            $table->json('attachments')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('resident_notifications', fn (Blueprint $table) => $table->dropColumn('attachments'));
        Schema::table('document_requests', fn (Blueprint $table) => $table->dropColumn(['archived_at', 'admin_read_at']));
    }
};
