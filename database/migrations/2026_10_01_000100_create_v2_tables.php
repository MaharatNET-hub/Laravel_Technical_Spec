<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // company profile and site settings (key => JSON value)
        Schema::create('v2_settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->json('value')->nullable();
            $t->timestamps();
        });

        Schema::create('v2_services', function (Blueprint $t) {
            $t->id();
            $t->string('icon', 40)->default('bolt');
            $t->string('title_en');
            $t->string('title_ar');
            $t->text('summary_en')->nullable();
            $t->text('summary_ar')->nullable();
            $t->unsignedInteger('sort')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('v2_projects', function (Blueprint $t) {
            $t->id();
            $t->string('slug')->unique();
            $t->string('title_en');
            $t->string('title_ar');
            $t->string('category', 60)->nullable();
            $t->string('location_en')->nullable();
            $t->string('location_ar')->nullable();
            $t->unsignedSmallInteger('year')->nullable();
            $t->text('summary_en')->nullable();
            $t->text('summary_ar')->nullable();
            $t->text('body_en')->nullable();
            $t->text('body_ar')->nullable();
            $t->string('image')->nullable();
            $t->string('accent', 9)->default('#1f4fbf');
            $t->boolean('featured')->default(false);
            $t->unsignedInteger('sort')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        // a technical submittal sent through the site's form
        Schema::create('v2_submissions', function (Blueprint $t) {
            $t->id();
            $t->string('code', 16)->unique(); // tracking code given to the client
            $t->string('status', 20)->default('uploading'); // uploading, received, analysing, review, issued, archived
            $t->string('client_name');
            $t->string('client_company')->nullable();
            $t->string('client_email');
            $t->string('client_phone', 40)->nullable();
            $t->string('project_name');
            $t->string('submittal_no')->nullable();
            $t->string('discipline', 40)->default('Electrical');
            $t->string('title')->nullable();
            $t->text('notes')->nullable();
            $t->string('file_name')->nullable();
            $t->unsignedBigInteger('file_size')->default(0);
            $t->unsignedInteger('page_count')->nullable();
            $t->string('decision')->nullable();
            $t->unsignedInteger('comment_count')->nullable();
            $t->string('engineer')->nullable();
            $t->string('locale', 2)->default('en');
            $t->timestamp('analysed_at')->nullable();
            $t->timestamp('issued_at')->nullable();
            $t->timestamp('emailed_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'created_at']);
        });

        Schema::create('v2_messages', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email');
            $t->string('phone', 40)->nullable();
            $t->string('subject')->nullable();
            $t->text('body');
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });

        Schema::create('v2_activities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('submission_id')->nullable()->constrained('v2_submissions')->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('action', 60);
            $t->text('detail')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['v2_activities', 'v2_messages', 'v2_submissions', 'v2_projects', 'v2_services', 'v2_settings'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
