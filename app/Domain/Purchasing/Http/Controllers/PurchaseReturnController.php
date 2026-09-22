<?php

namespace App\Domain\Purchasing\Http\Controllers;

use App\Domain\Accounting\Models\ChartOfAccount;
use App\Domain\Accounting\Services\AccountingService;
use App\Domain\Inventory\Models\ImeiUnit;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Purchasing\Models\PurchaseInvoice;
use App\Domain\Purchasing\Models\PurchaseItem;
use App\Domain\Purchasing\Models\PurchaseReturn;
use App\Domain\Purchasing\Models\PurchaseReturnItem;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class PurchaseReturnController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly AccountingService $accounting,
    ) {}

    public function store(Request $request)
    {
        $data = $request->validate([
            'purchase_invoice_id' => ['required', 'exists:purchase_invoices,id'],
            'return_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_item_id' => ['required', 'exists:purchase_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.imei_unit_id' => ['nullable', 'exists:imei_units,id'],
        ]);

        $invoice = PurchaseInvoice::with('distributor')->findOrFail($data['purchase_invoice_id']);

        try {
            $purchaseReturn = DB::transaction(function () use ($data, $invoice, $request) {
                $totalAmount = 0.0;
                $lineResults = [];

                foreach ($data['items'] as $itemData) {
                    $purchaseItem = PurchaseItem::where('id', $itemData['purchase_item_id'])
                        ->where('purchase_invoice_id', $invoice->id)
                        ->firstOrFail();
                    $sku = $purchaseItem->sku;
                    $quantity = (int) $itemData['quantity'];
                    $imeiUnitId = null;

                    if ($sku->resolvedImeiTrackingEnabled()) {
                        if ($quantity !== 1) {
                            throw new InvalidArgumentException('IMEI-tracked items must be returned one at a time.');
                        }
                        if (empty($itemData['imei_unit_id'])) {
                            throw new InvalidArgumentException('Select the specific IMEI unit to return.');
                        }
                        $unit = ImeiUnit::findOrFail($itemData['imei_unit_id']);
                        if ($unit->purchase_item_id !== $purchaseItem->id) {
                            throw new InvalidArgumentException('That IMEI does not belong to this purchase line.');
                        }
                        $this->inventory->returnImeiToDistributor($unit, $invoice->branch_id, $request->user()->id);
                        $imeiUnitId = $unit->id;
                    } else {
                        $this->inventory->returnQuantityToDistributor(
                            sku: $sku,
                            branchId: $invoice->branch_id,
                            quantity: $quantity,
                            purchaseItem: $purchaseItem,
                            createdBy: $request->user()->id,
                        );
                    }

                    $lineTotal = round((float) $purchaseItem->unit_cost * $quantity, 2);
                    $totalAmount += $lineTotal;

                    $lineResults[] = [
                        'purchase_item_id' => $purchaseItem->id,
                        'imei_unit_id' => $imeiUnitId,
                        'quantity' => $quantity,
                        'unit_cost' => $purchaseItem->unit_cost,
                        'line_total' => $lineTotal,
                    ];
                }

                $purchaseReturn = PurchaseReturn::create([
                    'purchase_invoice_id' => $invoice->id,
                    'distributor_id' => $invoice->distributor_id,
                    'return_date' => $data['return_date'],
                    'reason' => $data['reason'] ?? null,
                    'total_amount' => round($totalAmount, 2),
                    'created_by' => $request->user()->id,
                ]);

                foreach ($lineResults as $line) {
                    PurchaseReturnItem::create(['purchase_return_id' => $purchaseReturn->id, ...$line]);
                }

                $payableAccount = ChartOfAccount::where('code', '2000')->firstOrFail();
                $inventoryAccount = ChartOfAccount::where('code', '1200')->firstOrFail();

                $this->accounting->postEntry(
                    lines: [
                        ['account_id' => $payableAccount->id, 'debit' => $totalAmount, 'party' => $invoice->distributor],
                        ['account_id' => $inventoryAccount->id, 'credit' => $totalAmount],
                    ],
                    narration: "Purchase return for {$invoice->invoice_number} — {$invoice->distributor->name}",
                    branchId: $invoice->branch_id,
                    entryDate: $data['return_date'],
                    reference: $purchaseReturn,
                    createdBy: $request->user()->id,
                );

                return $purchaseReturn;
            });
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['items' => [$e->getMessage()]]);
        }

        return response()->json(['data' => $purchaseReturn->load('items')], 201);
    }
}
