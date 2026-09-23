<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Catalog\Models\ProductVariantColor;
use App\Domain\Inventory\Models\ImeiUnit;
use App\Domain\Inventory\Models\InventoryStock;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Purchasing\Models\PurchaseItem;
use InvalidArgumentException;

class InventoryService
{
    /**
     * Bring a purchased line item into stock: either as individually
     * tracked IMEI units, or as a quantity bump on the cached stock row.
     * Always appends a stock_movements record — the append-only source of
     * truth — so quantity is never silently overwritten.
     *
     * @param  array<int, array{imei1: string, imei2?: ?string, serial_number?: ?string}>  $imeis
     */
    public function receivePurchaseStock(
        PurchaseItem $purchaseItem,
        int $branchId,
        array $imeis = [],
        ?int $createdBy = null,
    ): void {
        $sku = $purchaseItem->sku;

        if ($sku->resolvedImeiTrackingEnabled()) {
            if (count($imeis) !== $purchaseItem->quantity) {
                throw new InvalidArgumentException(sprintf(
                    'This SKU requires IMEI tracking: expected %d IMEI(s), got %d.',
                    $purchaseItem->quantity,
                    count($imeis),
                ));
            }

            foreach ($imeis as $imeiData) {
                $this->assertImeiNotDuplicate($imeiData['imei1'] ?? null, $imeiData['imei2'] ?? null);

                $unit = ImeiUnit::create([
                    'product_variant_color_id' => $sku->id,
                    'branch_id' => $branchId,
                    'purchase_item_id' => $purchaseItem->id,
                    'imei1' => $imeiData['imei1'],
                    'imei2' => $imeiData['imei2'] ?? null,
                    'serial_number' => $imeiData['serial_number'] ?? null,
                    'status' => ImeiUnit::STATUS_IN_STOCK,
                    'is_demo' => $imeiData['is_demo'] ?? false,
                    'warranty_months' => $purchaseItem->warranty_months,
                    'purchased_at' => $purchaseItem->purchaseInvoice->purchase_date,
                ]);

                StockMovement::create([
                    'branch_id' => $branchId,
                    'product_variant_color_id' => $sku->id,
                    'imei_unit_id' => $unit->id,
                    'movement_type' => StockMovement::TYPE_PURCHASE,
                    'quantity_change' => 1,
                    'unit_cost' => $purchaseItem->unit_cost,
                    'reference_type' => 'purchase_invoice',
                    'reference_id' => $purchaseItem->purchase_invoice_id,
                    'created_by' => $createdBy,
                ]);
            }

            return;
        }

        StockMovement::create([
            'branch_id' => $branchId,
            'product_variant_color_id' => $sku->id,
            'movement_type' => StockMovement::TYPE_PURCHASE,
            'quantity_change' => $purchaseItem->quantity,
            'unit_cost' => $purchaseItem->unit_cost,
            'reference_type' => 'purchase_invoice',
            'reference_id' => $purchaseItem->purchase_invoice_id,
            'created_by' => $createdBy,
        ]);

        $stock = InventoryStock::firstOrCreate(
            ['branch_id' => $branchId, 'product_variant_color_id' => $sku->id],
            ['quantity' => 0, 'demo_quantity' => 0]
        );
        $stock->increment('quantity', $purchaseItem->quantity);

        if ($purchaseItem->demo_quantity > 0) {
            $stock->increment('demo_quantity', $purchaseItem->demo_quantity);
        }
    }

    /**
     * Consume `$quantity` units of a quantity-based SKU from stock using
     * FIFO: oldest purchase batch (lowest id — a proxy for purchase order
     * since batches aren't normally backdated) with remaining_quantity > 0
     * is drawn from first. Returns the weighted-average unit cost across
     * whichever batches were actually consumed, and the id of the first
     * (oldest) batch touched, for traceability.
     *
     * @return array{unit_cost: float, purchase_item_id: int}
     */
    public function consumeQuantityForSale(
        ProductVariantColor $sku,
        int $branchId,
        int $quantity,
        ?int $createdBy = null,
        bool $allowNegativeStock = false,
    ): array {
        $stock = InventoryStock::where('branch_id', $branchId)
            ->where('product_variant_color_id', $sku->id)
            ->first();

        $available = $stock?->quantity ?? 0;
        if (! $allowNegativeStock && $available < $quantity) {
            throw new InvalidArgumentException(
                "Insufficient stock for {$sku->sku}: {$available} available, {$quantity} requested."
            );
        }

        $remaining = $quantity;
        $totalCost = 0.0;
        $primaryPurchaseItemId = null;

        $batches = PurchaseItem::where('product_variant_color_id', $sku->id)
            ->where('remaining_quantity', '>', 0)
            ->orderBy('id')
            ->get();

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }
            $take = min($batch->remaining_quantity, $remaining);
            $batch->decrement('remaining_quantity', $take);
            $totalCost += $take * (float) $batch->unit_cost;
            $primaryPurchaseItemId ??= $batch->id;
            $remaining -= $take;
        }

        if ($remaining > 0) {
            // Negative-stock override was used, or batches are out of sync
            // with the cache — fall back to the most recent known cost.
            $lastCost = PurchaseItem::where('product_variant_color_id', $sku->id)
                ->orderByDesc('id')
                ->value('unit_cost') ?? 0;
            $totalCost += $remaining * (float) $lastCost;
        }

        InventoryStock::updateOrCreate(
            ['branch_id' => $branchId, 'product_variant_color_id' => $sku->id],
            []
        )->decrement('quantity', $quantity);

        StockMovement::create([
            'branch_id' => $branchId,
            'product_variant_color_id' => $sku->id,
            'movement_type' => StockMovement::TYPE_SALE,
            'quantity_change' => -$quantity,
            'unit_cost' => round($totalCost / $quantity, 2),
            'created_by' => $createdBy,
        ]);

        return [
            'unit_cost' => round($totalCost / $quantity, 2),
            'purchase_item_id' => $primaryPurchaseItemId,
        ];
    }

    /**
     * Sell one specific IMEI-tracked unit. Returns its original purchase
     * cost so the sale can snapshot an accurate profit figure.
     */
    public function sellImeiUnit(ImeiUnit $unit, int $branchId, ?int $createdBy = null): float
    {
        if ($unit->status !== ImeiUnit::STATUS_IN_STOCK) {
            throw new InvalidArgumentException(
                "IMEI {$unit->imei1} is not available for sale (current status: {$unit->status})."
            );
        }

        $unit->update([
            'status' => ImeiUnit::STATUS_SOLD,
            'sold_at' => now()->toDateString(),
        ]);

        StockMovement::create([
            'branch_id' => $branchId,
            'product_variant_color_id' => $unit->product_variant_color_id,
            'imei_unit_id' => $unit->id,
            'movement_type' => StockMovement::TYPE_SALE,
            'quantity_change' => -1,
            'unit_cost' => $unit->purchaseItem->unit_cost,
            'created_by' => $createdBy,
        ]);

        return (float) $unit->purchaseItem->unit_cost;
    }

    /**
     * Sales return of a specific IMEI unit that's going back into sellable
     * stock. The unit keeps its original purchase_item_id, so its cost
     * history stays intact for any future resale.
     */
    public function restockImeiFromSalesReturn(ImeiUnit $unit, int $branchId, ?int $createdBy = null): void
    {
        $unit->update(['status' => ImeiUnit::STATUS_IN_STOCK, 'sold_at' => null]);

        StockMovement::create([
            'branch_id' => $branchId,
            'product_variant_color_id' => $unit->product_variant_color_id,
            'imei_unit_id' => $unit->id,
            'movement_type' => StockMovement::TYPE_SALES_RETURN,
            'quantity_change' => 1,
            'unit_cost' => $unit->purchaseItem->unit_cost,
            'created_by' => $createdBy,
        ]);
    }

    /**
     * Sales return of a quantity-based SKU that's going back into sellable
     * stock: credits the quantity back to the FIFO batch it was originally
     * drawn from (so that batch's cost is available again for the next sale).
     */
    public function restockQuantityFromSalesReturn(
        ProductVariantColor $sku,
        int $branchId,
        int $quantity,
        ?int $purchaseItemId,
        float $unitCost,
        ?int $createdBy = null,
    ): void {
        if ($purchaseItemId) {
            PurchaseItem::where('id', $purchaseItemId)->increment('remaining_quantity', $quantity);
        }

        InventoryStock::firstOrCreate(
            ['branch_id' => $branchId, 'product_variant_color_id' => $sku->id],
            ['quantity' => 0]
        )->increment('quantity', $quantity);

        StockMovement::create([
            'branch_id' => $branchId,
            'product_variant_color_id' => $sku->id,
            'movement_type' => StockMovement::TYPE_SALES_RETURN,
            'quantity_change' => $quantity,
            'unit_cost' => $unitCost,
            'created_by' => $createdBy,
        ]);
    }

    /**
     * Send one specific in-stock IMEI unit back to the distributor.
     */
    public function returnImeiToDistributor(ImeiUnit $unit, int $branchId, ?int $createdBy = null): void
    {
        if ($unit->status !== ImeiUnit::STATUS_IN_STOCK) {
            throw new InvalidArgumentException(
                "IMEI {$unit->imei1} is not in stock and cannot be returned to the distributor (current status: {$unit->status})."
            );
        }

        $unit->update(['status' => ImeiUnit::STATUS_RETURNED]);

        StockMovement::create([
            'branch_id' => $branchId,
            'product_variant_color_id' => $unit->product_variant_color_id,
            'imei_unit_id' => $unit->id,
            'movement_type' => StockMovement::TYPE_PURCHASE_RETURN,
            'quantity_change' => -1,
            'unit_cost' => $unit->purchaseItem->unit_cost,
            'created_by' => $createdBy,
        ]);
    }

    /**
     * Send `$quantity` units of a quantity-based SKU back to the distributor
     * from one specific purchase batch — only unsold units (still counted in
     * that batch's remaining_quantity) can be returned this way.
     */
    public function returnQuantityToDistributor(
        ProductVariantColor $sku,
        int $branchId,
        int $quantity,
        PurchaseItem $purchaseItem,
        ?int $createdBy = null,
    ): void {
        if ($purchaseItem->remaining_quantity < $quantity) {
            throw new InvalidArgumentException(
                "Only {$purchaseItem->remaining_quantity} unit(s) of this purchase batch are still in stock; cannot return {$quantity}."
            );
        }

        $purchaseItem->decrement('remaining_quantity', $quantity);

        InventoryStock::firstOrCreate(
            ['branch_id' => $branchId, 'product_variant_color_id' => $sku->id],
            ['quantity' => 0]
        )->decrement('quantity', $quantity);

        StockMovement::create([
            'branch_id' => $branchId,
            'product_variant_color_id' => $sku->id,
            'movement_type' => StockMovement::TYPE_PURCHASE_RETURN,
            'quantity_change' => -$quantity,
            'unit_cost' => $purchaseItem->unit_cost,
            'created_by' => $createdBy,
        ]);
    }

    /**
     * Move one specific in-stock IMEI unit from one branch to another.
     * Its purchase_item_id (cost basis) never changes — only its location.
     */
    public function transferImeiUnit(
        ImeiUnit $unit,
        int $fromBranchId,
        int $toBranchId,
        ?int $createdBy = null,
    ): void {
        if ($unit->branch_id !== $fromBranchId) {
            throw new InvalidArgumentException("IMEI {$unit->imei1} is not currently at the source branch.");
        }
        if ($unit->status !== ImeiUnit::STATUS_IN_STOCK) {
            throw new InvalidArgumentException(
                "IMEI {$unit->imei1} is not in stock and cannot be transferred (current status: {$unit->status})."
            );
        }

        $unit->update(['branch_id' => $toBranchId]);

        StockMovement::create([
            'branch_id' => $fromBranchId,
            'product_variant_color_id' => $unit->product_variant_color_id,
            'imei_unit_id' => $unit->id,
            'movement_type' => StockMovement::TYPE_TRANSFER_OUT,
            'quantity_change' => -1,
            'created_by' => $createdBy,
        ]);

        StockMovement::create([
            'branch_id' => $toBranchId,
            'product_variant_color_id' => $unit->product_variant_color_id,
            'imei_unit_id' => $unit->id,
            'movement_type' => StockMovement::TYPE_TRANSFER_IN,
            'quantity_change' => 1,
            'created_by' => $createdBy,
        ]);
    }

    /**
     * Move `$quantity` units of a quantity-based SKU from one branch's
     * cached stock to another's. FIFO cost batches aren't branch-scoped
     * (remaining_quantity tracks "unsold company-wide"), so a transfer only
     * touches the per-branch location cache — never the cost basis.
     */
    public function transferQuantity(
        ProductVariantColor $sku,
        int $fromBranchId,
        int $toBranchId,
        int $quantity,
        ?int $createdBy = null,
    ): void {
        $fromStock = InventoryStock::where('branch_id', $fromBranchId)
            ->where('product_variant_color_id', $sku->id)
            ->first();

        $available = $fromStock?->quantity ?? 0;
        if ($available < $quantity) {
            throw new InvalidArgumentException(
                "Insufficient stock for {$sku->sku} at the source branch: {$available} available, {$quantity} requested."
            );
        }

        $fromStock->decrement('quantity', $quantity);

        InventoryStock::firstOrCreate(
            ['branch_id' => $toBranchId, 'product_variant_color_id' => $sku->id],
            ['quantity' => 0]
        )->increment('quantity', $quantity);

        StockMovement::create([
            'branch_id' => $fromBranchId,
            'product_variant_color_id' => $sku->id,
            'movement_type' => StockMovement::TYPE_TRANSFER_OUT,
            'quantity_change' => -$quantity,
            'created_by' => $createdBy,
        ]);

        StockMovement::create([
            'branch_id' => $toBranchId,
            'product_variant_color_id' => $sku->id,
            'movement_type' => StockMovement::TYPE_TRANSFER_IN,
            'quantity_change' => $quantity,
            'created_by' => $createdBy,
        ]);
    }

    private function assertImeiNotDuplicate(?string $imei1, ?string $imei2): void
    {
        foreach (array_filter([$imei1, $imei2]) as $imei) {
            $exists = ImeiUnit::where('imei1', $imei)->orWhere('imei2', $imei)->exists();
            if ($exists) {
                throw new InvalidArgumentException("IMEI {$imei} already exists in the system.");
            }
        }
    }
}
