<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Riwayat pembelian stok: setiap stok masuk punya vendor, harga satuan, tanggal terima, dan no. PO. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('stock_receipts', function (Blueprint $t) {
            $t->id();
            $t->morphs('stockable');
            $t->foreignId('stock_movement_id')->nullable()->unique()->constrained('stock_movements')->nullOnDelete();
            $t->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $t->decimal('qty', 12, 2);
            $t->decimal('unit_price', 15, 2)->default(0);
            $t->date('received_at');
            $t->string('reference_no')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index('received_at');
        });

        // data lama: setiap pergerakan "masuk" dijadikan satu pembelian memakai vendor & harga yang tercatat pada barangnya
        $map = [
            'App\\Models\\Accessory' => ['accessories', 'unit_price'],
            'App\\Models\\Consumable' => ['consumables', 'unit_price'],
            'App\\Models\\Component' => ['components', 'price'],
        ];
        foreach (DB::table('stock_movements')->where('type', 'in')->orderBy('id')->get() as $m) {
            if (! isset($map[$m->stockable_type])) continue;
            [$table, $priceCol] = $map[$m->stockable_type];
            $item = DB::table($table)->where('id', $m->stockable_id)->first();
            DB::table('stock_receipts')->insert([
                'stockable_type' => $m->stockable_type, 'stockable_id' => $m->stockable_id, 'stock_movement_id' => $m->id,
                'vendor_id' => $item->vendor_id ?? null, 'qty' => $m->qty, 'unit_price' => $item->{$priceCol} ?? 0,
                'received_at' => substr((string) $m->moved_at, 0, 10), 'reference_no' => null, 'notes' => $m->notes,
                'created_by' => $m->created_by, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_receipts');
    }
};
