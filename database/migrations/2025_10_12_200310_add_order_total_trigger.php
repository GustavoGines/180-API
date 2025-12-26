<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            // 1) Función que recalcula total de un pedido
            DB::unprepared(<<<'SQL'
         CREATE OR REPLACE FUNCTION update_order_total(p_order_id BIGINT)
         RETURNS VOID AS $$
         BEGIN
           UPDATE orders
           SET total = COALESCE((
               SELECT SUM(qty * unit_price)::numeric
               FROM order_items
               WHERE order_id = p_order_id
           ), 0),
           updated_at = NOW()
           WHERE id = p_order_id;
         END;
         $$ LANGUAGE plpgsql;
        SQL);

            // 2) Triggers en INSERT/UPDATE/DELETE de order_items
            DB::unprepared(<<<'SQL'
         CREATE OR REPLACE FUNCTION trg_order_items_after_change()
         RETURNS TRIGGER AS $$
         DECLARE
           v_order_id BIGINT;
         BEGIN
           IF (TG_OP = 'DELETE') THEN
             v_order_id := OLD.order_id;
           ELSE
             v_order_id := NEW.order_id;
           END IF;
         
           PERFORM update_order_total(v_order_id);
           RETURN NULL;
         END;
         $$ LANGUAGE plpgsql;
         
         DROP TRIGGER IF EXISTS trg_order_items_after_insupddel ON order_items;
         
         CREATE TRIGGER trg_order_items_after_insupddel
         AFTER INSERT OR UPDATE OR DELETE ON order_items
         FOR EACH ROW EXECUTE FUNCTION trg_order_items_after_change();
        SQL);

            // (Opcional) constraint: depósito <= total (evita incoherencias)
            DB::unprepared(<<<'SQL'
         ALTER TABLE orders
         ADD CONSTRAINT deposit_not_greater_than_total
         CHECK (deposit IS NULL OR deposit <= total);
        SQL);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::unprepared('ALTER TABLE orders DROP CONSTRAINT IF EXISTS deposit_not_greater_than_total;');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_order_items_after_insupddel ON order_items;');
            DB::unprepared('DROP FUNCTION IF EXISTS trg_order_items_after_change();');
            DB::unprepared('DROP FUNCTION IF EXISTS update_order_total(BIGINT);');
        }
    }
};
