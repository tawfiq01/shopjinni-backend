<?php

namespace App\Domain\Sales\Http\Controllers;

use App\Domain\Accounting\Models\ChartOfAccount;
use App\Domain\Accounting\Models\PaymentMethod;
use App\Domain\Accounting\Services\AccountingService;
use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\ProductVariantColor;
use App\Domain\Customers\Models\Customer;
use App\Domain\Inventory\Models\ImeiUnit;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Purchasing\Models\Distributor;
use App\Domain\Purchasing\Models\PurchaseInvoice;
use App\Domain\Purchasing\Models\PurchaseItem;
use App\Domain\Sales\Models\PhoneExchange;
use App\Domain\Sales\Models\SaleItem;
use App\Domain\Sales\Models\SalePayment;
use App\Domain\Sales\Models\SalesInvoice;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class PhoneExchangeController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly AccountingService $accounting,
    ) {}

    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_id' => ['nullable', 'exists:customers,id'],
            'exchange_date' => ['required', 'date'],
            'old_phone.product_variant_color_id' => ['required', 'exists:product_variant_colors,id'],
            'old_phone.exchange_value' => ['required', 'numeric', 'min:0'],
            'old_phone.imei1' => ['nullable', 'string', 'max:32'],
            'old_phone.imei2' => ['nullable', 'string', 'max:32'],
            'old_phone.serial_number' => ['nullable', 'string', 'max:64'],
            'old_phone.warranty_months' => ['nullable', 'integer', 'min:0'],
            'new_phone.product_variant_color_id' => ['required', 'exists:product_variant_colors,id'],
            'new_phone.imei_unit_id' => ['nullable', 'exists:imei_units,id'],
            'new_phone.unit_price' => ['required', 'numeric', 'min:0'],
            'payments' => ['nullable', 'array'],
            'payments.*.payment_method_id' => ['required_with:payments', 'exists:payment_methods,id'],
            'payments.*.amount' => ['required_with:payments', 'numeric', 'min:0.01'],
            'refund_method_id' => ['nullable', 'exists:payment_methods,id'],
        ]);

        $branchId = $request->user()->branch_id ?? Branch::where('is_main', true)->value('id');
        $customer = isset($data['customer_id']) ? Customer::find($data['customer_id']) : null;

        $oldSku = ProductVariantColor::findOrFail($data['old_phone']['product_variant_color_id']);
        $newSku = ProductVariantColor::findOrFail($data['new_phone']['product_variant_color_id']);

        if ($oldSku->resolvedImeiTrackingEnabled() && empty($data['old_phone']['imei1'])) {
            throw ValidationException::withMessages(['old_phone' => ['The old phone\'s SKU requires an IMEI.']]);
        }
        if ($newSku->resolvedImeiTrackingEnabled() && empty($data['new_phone']['imei_unit_id'])) {
            throw ValidationException::withMessages(['new_phone' => ['Select the specific IMEI unit for the new phone.']]);
        }

        $exchangeValue = round((float) $data['old_phone']['exchange_value'], 2);
        $newPrice = round((float) $data['new_phone']['unit_price'], 2);
        $priceDifference = round($newPrice - $exchangeValue, 2);
        $paidAmount = round(collect($data['payments'] ?? [])->sum('amount'), 2);

        if ($priceDifference > 0.005 && $paidAmount > $priceDifference + 0.005) {
            throw ValidationException::withMessages(['payments' => ['Total payments cannot exceed the price difference owed.']]);
        }
        if ($priceDifference < -0.005 && empty($data['refund_method_id'])) {
            throw ValidationException::withMessages(['refund_method_id' => ['The old phone is worth more than the new one — a refund method is required.']]);
        }

        try {
            $exchange = DB::transaction(function () use ($data, $branchId, $customer, $oldSku, $newSku, $exchangeValue, $newPrice, $priceDifference, $paidAmount, $request) {
                // 1. Bring the old phone into stock via a lightweight internal
                // "purchase" from a system trade-in distributor — this reuses
                // the normal purchase-batch/FIFO/IMEI machinery unmodified.
                $tradeInDistributor = Distributor::firstOrCreate(
                    ['name' => 'Customer Trade-In'],
                    ['mobile' => 'N/A', 'notes' => 'System distributor for phones acquired via customer exchange.']
                );

                $intakeInvoice = PurchaseInvoice::create([
                    'branch_id' => $branchId,
                    'distributor_id' => $tradeInDistributor->id,
                    'invoice_number' => 'EXCH-IN-'.now()->format('ymdHis').'-'.random_int(0, 999),
                    'purchase_date' => $data['exchange_date'],
                    'subtotal' => $exchangeValue,
                    'total' => $exchangeValue,
                    'paid_amount' => $exchangeValue,
                    'due_amount' => 0,
                    'created_by' => $request->user()->id,
                ]);

                $oldPurchaseItem = PurchaseItem::create([
                    'purchase_invoice_id' => $intakeInvoice->id,
                    'product_variant_color_id' => $oldSku->id,
                    'quantity' => 1,
                    'demo_quantity' => 0,
                    'unit_cost' => $exchangeValue,
                    'line_total' => $exchangeValue,
                    'warranty_months' => $data['old_phone']['warranty_months'] ?? null,
                    'remaining_quantity' => 1,
                ]);

                $this->inventory->receivePurchaseStock(
                    purchaseItem: $oldPurchaseItem,
                    branchId: $branchId,
                    imeis: $oldSku->resolvedImeiTrackingEnabled() ? [[
                        'imei1' => $data['old_phone']['imei1'],
                        'imei2' => $data['old_phone']['imei2'] ?? null,
                        'serial_number' => $data['old_phone']['serial_number'] ?? null,
                    ]] : [],
                    createdBy: $request->user()->id,
                );

                $oldImeiUnitId = $oldSku->resolvedImeiTrackingEnabled()
                    ? ImeiUnit::where('purchase_item_id', $oldPurchaseItem->id)->value('id')
                    : null;

                // 2. Sell the new phone out, exactly like a normal POS sale.
                if ($newSku->resolvedImeiTrackingEnabled()) {
                    $unit = ImeiUnit::findOrFail($data['new_phone']['imei_unit_id']);
                    if ($unit->product_variant_color_id !== $newSku->id) {
                        throw new InvalidArgumentException('That IMEI does not belong to the selected new phone SKU.');
                    }
                    $unitCost = $this->inventory->sellImeiUnit($unit, $branchId, $request->user()->id);
                    $newPurchaseItemId = $unit->purchase_item_id;
                    $newImeiUnitId = $unit->id;
                } else {
                    $result = $this->inventory->consumeQuantityForSale($newSku, $branchId, 1, $request->user()->id);
                    $unitCost = $result['unit_cost'];
                    $newPurchaseItemId = $result['purchase_item_id'];
                    $newImeiUnitId = null;
                }

                $profit = round($newPrice - $unitCost, 2);
                $paidForInvoice = round($exchangeValue + $paidAmount, 2);
                $dueForInvoice = max(0.0, round($newPrice - $paidForInvoice, 2));

                if ($dueForInvoice > 0.005 && ! $customer) {
                    throw new InvalidArgumentException('A customer is required when the exchange leaves a due balance.');
                }

                $salesInvoice = SalesInvoice::create([
                    'branch_id' => $branchId,
                    'customer_id' => $customer?->id,
                    'invoice_number' => 'SINV-'.now()->format('ymd').'-'.random_int(0, 9999),
                    'sale_date' => $data['exchange_date'],
                    'subtotal' => $newPrice,
                    'total' => $newPrice,
                    'total_cost' => round($unitCost, 2),
                    'profit' => $profit,
                    'paid_amount' => $paidForInvoice,
                    'due_amount' => $dueForInvoice,
                    'salesperson_id' => $request->user()->id,
                    'created_by' => $request->user()->id,
                ]);

                SaleItem::create([
                    'sales_invoice_id' => $salesInvoice->id,
                    'product_variant_color_id' => $newSku->id,
                    'imei_unit_id' => $newImeiUnitId,
                    'quantity' => 1,
                    'unit_price' => $newPrice,
                    'discount' => 0,
                    'line_total' => $newPrice,
                    'unit_cost' => $unitCost,
                    'profit' => $profit,
                    'purchase_item_id' => $newPurchaseItemId,
                ]);

                foreach ($data['payments'] ?? [] as $paymentData) {
                    SalePayment::create([
                        'sales_invoice_id' => $salesInvoice->id,
                        'payment_method_id' => $paymentData['payment_method_id'],
                        'amount' => $paymentData['amount'],
                        'paid_at' => $data['exchange_date'],
                        'created_by' => $request->user()->id,
                    ]);
                }

                $exchange = PhoneExchange::create([
                    'sales_invoice_id' => $salesInvoice->id,
                    'old_purchase_item_id' => $oldPurchaseItem->id,
                    'old_imei_unit_id' => $oldImeiUnitId,
                    'customer_id' => $customer?->id,
                    'exchange_value' => $exchangeValue,
                    'price_difference' => $priceDifference,
                    'created_by' => $request->user()->id,
                ]);

                // 3. One balanced journal entry covering the whole exchange.
                $inventoryAccount = ChartOfAccount::where('code', '1200')->firstOrFail();
                $revenueAccount = ChartOfAccount::where('code', '4000')->firstOrFail();
                $cogsAccount = ChartOfAccount::where('code', '5000')->firstOrFail();
                $receivableAccount = ChartOfAccount::where('code', '1100')->firstOrFail();

                $lines = [
                    ['account_id' => $inventoryAccount->id, 'debit' => $exchangeValue],
                ];

                foreach ($data['payments'] ?? [] as $paymentData) {
                    $method = PaymentMethod::findOrFail($paymentData['payment_method_id']);
                    $lines[] = ['account_id' => $method->chart_of_account_id, 'debit' => $paymentData['amount']];
                }

                if ($dueForInvoice > 0.005) {
                    $lines[] = ['account_id' => $receivableAccount->id, 'debit' => $dueForInvoice, 'party' => $customer];
                }

                if ($priceDifference < -0.005) {
                    $refundMethod = PaymentMethod::findOrFail($data['refund_method_id']);
                    $lines[] = ['account_id' => $refundMethod->chart_of_account_id, 'credit' => abs($priceDifference)];
                }

                $lines[] = ['account_id' => $revenueAccount->id, 'credit' => $newPrice];
                $lines[] = ['account_id' => $cogsAccount->id, 'debit' => round($unitCost, 2)];
                $lines[] = ['account_id' => $inventoryAccount->id, 'credit' => round($unitCost, 2)];

                $this->accounting->postEntry(
                    lines: $lines,
                    narration: "Phone exchange — {$salesInvoice->invoice_number}",
                    branchId: $branchId,
                    entryDate: $data['exchange_date'],
                    reference: $exchange,
                    createdBy: $request->user()->id,
                );

                return $exchange;
            });
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['exchange' => [$e->getMessage()]]);
        }

        return response()->json(['data' => $exchange->load(['salesInvoice', 'oldImeiUnit'])], 201);
    }
}
