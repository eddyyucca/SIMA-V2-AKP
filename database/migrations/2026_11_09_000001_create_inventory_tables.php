<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->enum('type', ['asset', 'accessory', 'consumable', 'component']);
            $t->string('code_prefix', 10)->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('departments', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('locations', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('branch')->nullable(); $t->text('address')->nullable();
            $t->timestamps(); $t->softDeletes();
        });
        Schema::create('vendors', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('contact_person')->nullable();
            $t->string('phone')->nullable(); $t->string('email')->nullable();
            $t->timestamps(); $t->softDeletes();
        });
        Schema::create('employees', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->foreignId('department_id')->nullable()->constrained();
            $t->string('position')->nullable();
            $t->string('phone')->nullable();
            $t->enum('status', ['active', 'resigned'])->default('active');
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps(); $t->softDeletes();
        });
        Schema::create('assets', function (Blueprint $t) {
            $t->id();
            $t->string('asset_tag')->unique();
            $t->foreignId('category_id')->constrained();
            $t->string('brand')->nullable();
            $t->string('model')->nullable();
            $t->string('serial_number')->nullable()->unique();
            $t->text('specs')->nullable();
            $t->string('photo_path')->nullable();
            $t->json('documents')->nullable();
            $t->date('purchase_date')->nullable();
            $t->foreignId('vendor_id')->nullable()->constrained();
            $t->string('invoice_no')->nullable();
            $t->decimal('purchase_price', 15, 2)->default(0);
            $t->date('warranty_until')->nullable();
            $t->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('location_id')->nullable()->constrained();
            $t->enum('status', ['available', 'deployed', 'repair', 'borrowed', 'lost', 'disposed', 'returned'])->default('available');
            $t->string('status_before_repair')->nullable();
            $t->enum('condition', ['good', 'minor_damage', 'major_damage'])->default('good');
            $t->text('notes')->nullable();
            $t->timestamps(); $t->softDeletes();
            $t->index('status');
        });
        Schema::create('accessories', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->foreignId('category_id')->nullable()->constrained();
            $t->string('brand_model')->nullable();
            $t->integer('qty_total')->default(0);
            $t->integer('qty_available')->default(0);
            $t->integer('min_qty')->default(0);
            $t->foreignId('location_id')->nullable()->constrained();
            $t->decimal('unit_price', 15, 2)->default(0);
            $t->foreignId('vendor_id')->nullable()->constrained();
            $t->string('photo_path')->nullable();
            $t->timestamps(); $t->softDeletes();
        });
        Schema::create('consumables', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->foreignId('category_id')->nullable()->constrained();
            $t->enum('unit', ['pcs', 'box', 'rim', 'meter', 'roll'])->default('pcs');
            $t->string('compatible_with')->nullable();
            $t->decimal('qty', 12, 2)->default(0);
            $t->decimal('min_qty', 12, 2)->default(0);
            $t->foreignId('location_id')->nullable()->constrained();
            $t->decimal('unit_price', 15, 2)->default(0);
            $t->foreignId('vendor_id')->nullable()->constrained();
            $t->timestamps(); $t->softDeletes();
        });
        Schema::create('components', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->foreignId('category_id')->nullable()->constrained();
            $t->text('specs')->nullable();
            $t->string('serial_number')->nullable();
            $t->integer('qty_total')->default(0);
            $t->integer('qty_available')->default(0);
            $t->integer('min_qty')->default(0);
            $t->foreignId('location_id')->nullable()->constrained();
            $t->decimal('price', 15, 2)->default(0);
            $t->foreignId('vendor_id')->nullable()->constrained();
            $t->date('warranty_until')->nullable();
            $t->timestamps(); $t->softDeletes();
        });
        Schema::create('licenses', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->enum('type', ['perpetual', 'subscription', 'domain', 'hosting', 'vps']);
            $t->text('license_key')->nullable();
            $t->integer('seats')->default(1);
            $t->date('purchase_date')->nullable();
            $t->date('expires_at')->nullable();
            $t->decimal('price', 15, 2)->default(0);
            $t->foreignId('vendor_id')->nullable()->constrained();
            $t->text('notes')->nullable();
            $t->timestamps(); $t->softDeletes();
        });
        Schema::create('asset_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('asset_id')->constrained();
            $t->enum('type', ['checkout', 'checkin', 'transfer']);
            $t->foreignId('from_employee_id')->nullable()->constrained('employees');
            $t->foreignId('to_employee_id')->nullable()->constrained('employees');
            $t->foreignId('location_id')->nullable()->constrained();
            $t->enum('condition', ['good', 'minor_damage', 'major_damage'])->default('good');
            $t->string('signature_receiver_path')->nullable();
            $t->string('signature_it_path')->nullable();
            $t->string('bast_number')->unique();
            $t->string('bast_pdf_path')->nullable();
            $t->text('notes')->nullable();
            $t->dateTime('assigned_at');
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestamps();
        });
        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id();
            $t->morphs('stockable');
            $t->enum('type', ['in', 'out', 'return', 'install', 'uninstall', 'adjust']);
            $t->decimal('qty', 12, 2);
            $t->foreignId('employee_id')->nullable()->constrained();
            $t->foreignId('asset_id')->nullable()->constrained();
            $t->foreignId('department_id')->nullable()->constrained();
            $t->string('reason')->nullable();
            $t->string('condition')->nullable();
            $t->text('notes')->nullable();
            $t->dateTime('moved_at');
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestamps();
        });
        Schema::create('license_seats', function (Blueprint $t) {
            $t->id();
            $t->foreignId('license_id')->constrained();
            $t->foreignId('employee_id')->nullable()->constrained();
            $t->foreignId('asset_id')->nullable()->constrained();
            $t->dateTime('assigned_at');
            $t->dateTime('revoked_at')->nullable();
            $t->timestamps();
        });
        Schema::create('repairs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('asset_id')->constrained();
            $t->foreignId('reported_by')->nullable()->constrained('employees');
            $t->dateTime('reported_at');
            $t->text('issue');
            $t->text('action')->nullable();
            $t->decimal('cost', 15, 2)->default(0);
            $t->string('technician_vendor')->nullable();
            $t->boolean('warranty_claim')->default(false);
            $t->enum('status', ['reported', 'in_progress', 'done'])->default('reported');
            $t->dateTime('finished_at')->nullable();
            $t->timestamps();
        });
        Schema::create('disposals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('asset_id')->constrained();
            $t->foreignId('requested_by')->nullable()->constrained('users');
            $t->text('reason');
            $t->boolean('data_wiped')->default(false);
            $t->enum('method', ['sell', 'auction', 'donate', 'ewaste'])->nullable();
            $t->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->dateTime('approved_at')->nullable();
            $t->string('ba_pdf_path')->nullable();
            $t->timestamps();
        });
        Schema::create('stock_opnames', function (Blueprint $t) {
            $t->id();
            $t->foreignId('location_id')->constrained();
            $t->dateTime('started_at');
            $t->dateTime('finished_at')->nullable();
            $t->foreignId('officer_id')->nullable()->constrained('users');
            $t->enum('status', ['running', 'finished'])->default('running');
            $t->timestamps();
        });
        Schema::create('stock_opname_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('stock_opname_id')->constrained()->cascadeOnDelete();
            $t->foreignId('asset_id')->constrained();
            $t->enum('result', ['found', 'wrong_location', 'missing']);
            $t->string('condition')->nullable();
            $t->text('notes')->nullable();
            $t->dateTime('scanned_at')->nullable();
            $t->timestamps();
            $t->unique(['stock_opname_id', 'asset_id']);
        });
    }

    public function down(): void
    {
        foreach (['stock_opname_items','stock_opnames','disposals','repairs','license_seats','stock_movements','asset_assignments','licenses','components','consumables','accessories','assets','employees','vendors','locations','departments','categories'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
