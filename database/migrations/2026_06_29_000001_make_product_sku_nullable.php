<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('CREATE TABLE products_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                branch_id INTEGER NULL,
                category_id INTEGER NOT NULL,
                unit_id INTEGER NOT NULL,
                name VARCHAR NOT NULL,
                sku VARCHAR NULL,
                barcode VARCHAR NULL,
                brand VARCHAR NULL,
                model_size VARCHAR NULL,
                image VARCHAR NULL,
                buying_price NUMERIC NOT NULL DEFAULT 0,
                selling_price NUMERIC NOT NULL DEFAULT 0,
                wholesale_price NUMERIC NULL,
                reorder_level NUMERIC NOT NULL DEFAULT 0,
                taxable TINYINT(1) NOT NULL DEFAULT 0,
                status VARCHAR CHECK(status IN (\'active\', \'inactive\')) NOT NULL DEFAULT \'active\',
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                FOREIGN KEY(branch_id) REFERENCES branches(id) ON DELETE SET NULL,
                FOREIGN KEY(category_id) REFERENCES categories(id) ON DELETE RESTRICT,
                FOREIGN KEY(unit_id) REFERENCES units(id) ON DELETE RESTRICT
            )');
            DB::statement('INSERT INTO products_new SELECT * FROM products');
            Schema::drop('products');
            DB::statement('ALTER TABLE products_new RENAME TO products');
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS products_sku_unique ON products (sku)');
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS products_barcode_unique ON products (barcode)');

            return;
        }

        Schema::table('products', function ($table) {
            $table->string('sku')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('CREATE TABLE products_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                branch_id INTEGER NULL,
                category_id INTEGER NOT NULL,
                unit_id INTEGER NOT NULL,
                name VARCHAR NOT NULL,
                sku VARCHAR NOT NULL,
                barcode VARCHAR NULL,
                brand VARCHAR NULL,
                model_size VARCHAR NULL,
                image VARCHAR NULL,
                buying_price NUMERIC NOT NULL DEFAULT 0,
                selling_price NUMERIC NOT NULL DEFAULT 0,
                wholesale_price NUMERIC NULL,
                reorder_level NUMERIC NOT NULL DEFAULT 0,
                taxable TINYINT(1) NOT NULL DEFAULT 0,
                status VARCHAR CHECK(status IN (\'active\', \'inactive\')) NOT NULL DEFAULT \'active\',
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                FOREIGN KEY(branch_id) REFERENCES branches(id) ON DELETE SET NULL,
                FOREIGN KEY(category_id) REFERENCES categories(id) ON DELETE RESTRICT,
                FOREIGN KEY(unit_id) REFERENCES units(id) ON DELETE RESTRICT
            )');
            DB::statement('INSERT INTO products_new SELECT * FROM products WHERE sku IS NOT NULL');
            Schema::drop('products');
            DB::statement('ALTER TABLE products_new RENAME TO products');
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS products_sku_unique ON products (sku)');
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS products_barcode_unique ON products (barcode)');

            return;
        }

        Schema::table('products', function ($table) {
            $table->string('sku')->nullable(false)->change();
        });
    }
};
