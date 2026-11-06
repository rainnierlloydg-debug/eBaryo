<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->text('purpose')->nullable();
            $table->decimal('fee', 10, 2)->default(0);
            $table->json('requirements')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->longText('body')->nullable();
            $table->string('uploaded_path')->nullable();
            $table->timestamps();
        });

        Schema::create('document_requests', function (Blueprint $table) {
            $table->id();
            $table->string('tracking_number')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->json('document_snapshot');
            $table->json('resident_snapshot');
            $table->string('purpose');
            $table->json('additional_information')->nullable();
            $table->string('status')->default('Submitted')->index();
            $table->decimal('fee', 10, 2)->default(0);
            $table->string('payment_status')->default('Unpaid');
            $table->text('rejection_reason')->nullable();
            $table->json('correction_fields')->nullable();
            $table->text('correction_instruction')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->string('generated_document_path')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('pickup_deadline')->nullable();
            $table->timestamp('pickup_reminder_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('requirement_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_request_id')->constrained()->cascadeOnDelete();
            $table->string('requirement_name');
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime_type', 80);
            $table->timestamps();
        });

        Schema::create('request_timelines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status');
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('cash_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->decimal('amount_paid', 10, 2);
            $table->dateTime('paid_at');
            $table->timestamps();
        });

        Schema::create('resident_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('message');
            $table->string('priority')->default('normal');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('national_id_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('email_hash', 64)->unique();
            $table->unsignedTinyInteger('failed_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamps();
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('national_id_attempts');
        Schema::dropIfExists('resident_notifications');
        Schema::dropIfExists('cash_payments');
        Schema::dropIfExists('request_timelines');
        Schema::dropIfExists('requirement_uploads');
        Schema::dropIfExists('document_requests');
        Schema::dropIfExists('document_templates');
        Schema::dropIfExists('documents');
    }
};
