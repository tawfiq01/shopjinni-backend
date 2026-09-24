<?php

namespace App\Domain\Sales\Http\Controllers;

use App\Domain\Accounting\Models\ChartOfAccount;
use App\Domain\Accounting\Models\PaymentMethod;
use App\Domain\Accounting\Services\AccountingService;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Sales\Models\SaleItem;
use App\Domain\Sales\Models\SalesInvoice;
use App\Domain\Sales\Models\SalesReturn;
use App\Domain\Sales\Models\SalesReturnItem;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class SalesReturnController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly AccountingService $accounting,
    ) {}

    public function store(Request $request)
    {
        $companyId = $request->user()->company_id;

        $data = $request->validate([
            'sales_invoice_id' => ['required', Rule::exists('sales_invoices', 'id')->where('company_id', $companyId)],
            'return_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
            'refund_method_id' => ['nullable', Rule::exists('payment_methods', 'id')->where('company_id', $companyId)],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sale_item_id' => ['required', Rule::exists('sale_items', 'id')->where('company_id', $companyId)],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.condition' => ['required', 'in:returned,damaged'],
            'items.*.restocked' => ['required', 'boolean'],
        ]);

        $invoice = SalesInvoice::with('customer')->findOrFail($data['sales_invoice_id']);

        foreach ($data['items'] as $itemData) {
            if ($itemData['condition'] === 'damaged' && $itemData['restocked']) {
                throw ValidationException::withMessages([
                    'items' => ['A damaged item cannot be marked as restocked.'],
                ]);
            }
        }

        if (empty($data['refund_method_id']) && ! $invoice->customer_id) {
            throw ValidationException::withMessages([
                'refund_method_id' => ['A refund method is required — this sale has no customer account to credit instead.'],
            ]);
        }

        try {
            $salesReturn = DB::transaction(function () use ($data, $invoice, $request) {
                $totalRefund = 0.0;
                $totalRestockedCost = 0.0;
                $lineResults = [];

                foreach ($data['items'] as $itemData) {
                    $saleItem = SaleItem::where('id', $itemData['sale_item_id'])
                        ->where('sales_invoice_id', $invoice->id)
                        ->firstOrFail();

                    $quantity = (int) $itemData['quantity'];
                    $alreadyReturned = SalesReturnItem::where('sale_item_id', $saleItem->id)->sum('quantity');
                    if ($alreadyReturned + $quantity > $saleItem->quantity) {
                        throw new InvalidArgumentException(
                            "Cannot return {$quantity} unit(s) of {$saleItem->sku->sku}: only ".
                            ($saleItem->quantity - $alreadyReturned).' remaining to return.'
                        );
                    }

                    if ($saleItem->imei_unit_id && $quantity !== 1) {
                        throw new InvalidArgumentException('IMEI-tracked items must be returned one at a time.');
                    }

                    $refundAmount = round(($saleItem->line_total / $saleItem->quantity) * $quantity, 2);
                    $lineCost = round((float) $saleItem->unit_cost * $quantity, 2);
                    $restocked = (bool) $itemData['restocked'];

                    if ($saleItem->imei_unit_id) {
                        $unit = $saleItem->imeiUnit;
                        if ($unit->status !== 'sold') {
                            throw new InvalidArgumentException(
                                "IMEI {$unit->imei1} is not marked as sold and cannot be returned (status: {$unit->status})."
                            );
                        }
                        if ($restocked) {
                            $this->inventory->restockImeiFromSalesReturn($unit, $invoice->branch_id, $request->user()->id);
                        } else {
                            $unit->update(['status' => $itemData['condition']]);
                        }
                    } elseif ($restocked) {
                        $this->inventory->restockQuantityFromSalesReturn(
                            sku: $saleItem->sku,
                            branchId: $invoice->branch_id,
                            quantity: $quantity,
                            purchaseItemId: $saleItem->purchase_item_id,
                            unitCost: (float) $saleItem->unit_cost,
                            createdBy: $request->user()->id,
                        );
                    }

                    $totalRefund += $refundAmount;
                    if ($restocked) {
                        $totalRestockedCost += $lineCost;
                    }

                    $lineResults[] = [
                        'sale_item_id' => $saleItem->id,
                        'imei_unit_id' => $saleItem->imei_unit_id,
                        'quantity' => $quantity,
                        'condition' => $itemData['condition'],
                        'restocked' => $restocked,
                        'refund_amount' => $refundAmount,
                    ];
                }

                $salesReturn = SalesReturn::create([
                    'sales_invoice_id' => $invoice->id,
                    'return_date' => $data['return_date'],
                    'reason' => $data['reason'] ?? null,
                    'total_refund' => round($totalRefund, 2),
                    'refund_method_id' => $data['refund_method_id'] ?? null,
                    'created_by' => $request->user()->id,
                ]);

                foreach ($lineResults as $line) {
                    SalesReturnItem::create(['sales_return_id' => $salesReturn->id, ...$line]);
                }

                $revenueAccount = ChartOfAccount::where('code', '4000')->firstOrFail();
                $receivableAccount = ChartOfAccount::where('code', '1100')->firstOrFail();
                $inventoryAccount = ChartOfAccount::where('code', '1200')->firstOrFail();
                $cogsAccount = ChartOfAccount::where('code', '5000')->firstOrFail();

                $journalLines = [
                    ['account_id' => $revenueAccount->id, 'debit' => $totalRefund],
                ];

                if (! empty($data['refund_method_id'])) {
                    $method = PaymentMethod::findOrFail($data['refund_method_id']);
                    $journalLines[] = ['account_id' => $method->chart_of_account_id, 'credit' => $totalRefund];
                } else {
                    $journalLines[] = ['account_id' => $receivableAccount->id, 'credit' => $totalRefund, 'party' => $invoice->customer];
                }

                if ($totalRestockedCost > 0) {
                    $journalLines[] = ['account_id' => $inventoryAccount->id, 'debit' => $totalRestockedCost];
                    $journalLines[] = ['account_id' => $cogsAccount->id, 'credit' => $totalRestockedCost];
                }

                $this->accounting->postEntry(
                    lines: $journalLines,
                    narration: "Sales return for {$invoice->invoice_number}",
                    branchId: $invoice->branch_id,
                    entryDate: $data['return_date'],
                    reference: $salesReturn,
                    createdBy: $request->user()->id,
                );

                return $salesReturn;
            });
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['items' => [$e->getMessage()]]);
        }

        return response()->json(['data' => $salesReturn->load('items')], 201);
    }
}
