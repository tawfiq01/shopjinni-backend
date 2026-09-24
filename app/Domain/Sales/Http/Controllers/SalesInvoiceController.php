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
use App\Domain\Sales\Http\Resources\SalesInvoiceResource;
use App\Domain\Sales\Models\SaleItem;
use App\Domain\Sales\Models\SalePayment;
use App\Domain\Sales\Models\SalesInvoice;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class SalesInvoiceController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly AccountingService $accounting,
    ) {}

    public function index(Request $request)
    {
        $query = SalesInvoice::with('customer')->orderByDesc('sale_date')->orderByDesc('id');

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->integer('customer_id'));
        }

        return SalesInvoiceResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function show(SalesInvoice $salesInvoice)
    {
        return new SalesInvoiceResource($salesInvoice);
    }

    public function store(Request $request)
    {
        $companyId = $request->user()->company_id;

        $data = $request->validate([
            'customer_id' => ['nullable', Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'invoice_number' => ['nullable', 'string', 'max:255', Rule::unique('sales_invoices', 'invoice_number')->where('company_id', $companyId)],
            'sale_date' => ['required', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_color_id' => ['required', Rule::exists('product_variant_colors', 'id')->where('company_id', $companyId)],
            'items.*.imei_unit_id' => ['nullable', Rule::exists('imei_units', 'id')->where('company_id', $companyId)],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
            'payments' => ['nullable', 'array'],
            'payments.*.payment_method_id' => ['required_with:payments', Rule::exists('payment_methods', 'id')->where('company_id', $companyId)],
            'payments.*.amount' => ['required_with:payments', 'numeric', 'min:0.01'],
            'payments.*.reference_no' => ['nullable', 'string', 'max:255'],
        ]);

        $branchId = $data['branch_id'] ?? $request->user()->branch_id ?? Branch::where('is_main', true)->value('id');
        $customer = isset($data['customer_id']) ? Customer::find($data['customer_id']) : null;

        // Validate IMEI/quantity shape up front (before touching inventory)
        // so a bad cart line doesn't get us partway through a transaction.
        foreach ($data['items'] as $itemData) {
            $sku = ProductVariantColor::findOrFail($itemData['product_variant_color_id']);
            $imeiTracked = $sku->resolvedImeiTrackingEnabled();

            if ($imeiTracked && empty($itemData['imei_unit_id'])) {
                throw ValidationException::withMessages([
                    'items' => ["{$sku->sku} requires selecting a specific IMEI unit."],
                ]);
            }
            if ($imeiTracked && (int) $itemData['quantity'] !== 1) {
                throw ValidationException::withMessages([
                    'items' => ["{$sku->sku} is IMEI-tracked: sell one unit per line."],
                ]);
            }
            if (! $imeiTracked && ! empty($itemData['imei_unit_id'])) {
                throw ValidationException::withMessages([
                    'items' => ["{$sku->sku} is not IMEI-tracked and cannot have an IMEI unit."],
                ]);
            }
        }

        $imeiIds = collect($data['items'])->pluck('imei_unit_id')->filter();
        if ($imeiIds->count() !== $imeiIds->unique()->count()) {
            throw ValidationException::withMessages(['items' => ['The same IMEI unit was added twice.']]);
        }

        $paidAmount = round(collect($data['payments'] ?? [])->sum('amount'), 2);

        try {
            $invoice = DB::transaction(function () use ($data, $branchId, $customer, $paidAmount, $request) {
                $subtotal = 0.0;
                $discountTotal = 0.0;
                $totalCost = 0.0;
                $totalProfit = 0.0;
                $lineResults = [];

                foreach ($data['items'] as $itemData) {
                    $sku = ProductVariantColor::findOrFail($itemData['product_variant_color_id']);
                    $quantity = (int) $itemData['quantity'];
                    $unitPrice = (float) $itemData['unit_price'];
                    $discount = (float) ($itemData['discount'] ?? 0);
                    $lineTotal = round($quantity * $unitPrice - $discount, 2);

                    if ($sku->resolvedImeiTrackingEnabled()) {
                        $unit = ImeiUnit::findOrFail($itemData['imei_unit_id']);
                        if ($unit->product_variant_color_id !== $sku->id) {
                            throw new InvalidArgumentException("That IMEI does not belong to {$sku->sku}.");
                        }
                        $unitCost = $this->inventory->sellImeiUnit($unit, $branchId, $request->user()->id);
                        $purchaseItemId = $unit->purchase_item_id;
                        $imeiUnitId = $unit->id;
                    } else {
                        $result = $this->inventory->consumeQuantityForSale($sku, $branchId, $quantity, $request->user()->id);
                        $unitCost = $result['unit_cost'];
                        $purchaseItemId = $result['purchase_item_id'];
                        $imeiUnitId = null;
                    }

                    $lineCost = round($unitCost * $quantity, 2);
                    $lineProfit = round($lineTotal - $lineCost, 2);

                    $subtotal += $quantity * $unitPrice;
                    $discountTotal += $discount;
                    $totalCost += $lineCost;
                    $totalProfit += $lineProfit;

                    $lineResults[] = [
                        'product_variant_color_id' => $sku->id,
                        'imei_unit_id' => $imeiUnitId,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'discount' => $discount,
                        'line_total' => $lineTotal,
                        'unit_cost' => $unitCost,
                        'profit' => $lineProfit,
                        'purchase_item_id' => $purchaseItemId,
                    ];
                }

                $total = round($subtotal - $discountTotal, 2);
                $dueAmount = round($total - $paidAmount, 2);

                if ($dueAmount < -0.005) {
                    throw new InvalidArgumentException('Total payments cannot exceed the invoice total.');
                }
                if ($dueAmount > 0.005 && ! $customer) {
                    throw new InvalidArgumentException('A customer is required when the sale is not fully paid.');
                }
                $dueAmount = max(0.0, $dueAmount);

                $invoice = SalesInvoice::create([
                    'branch_id' => $branchId,
                    'customer_id' => $customer?->id,
                    'invoice_number' => $data['invoice_number'] ?? $this->generateInvoiceNumber(),
                    'sale_date' => $data['sale_date'],
                    'subtotal' => round($subtotal, 2),
                    'discount' => round($discountTotal, 2),
                    'tax' => 0,
                    'total' => $total,
                    'total_cost' => round($totalCost, 2),
                    'profit' => round($totalProfit, 2),
                    'paid_amount' => $paidAmount,
                    'due_amount' => $dueAmount,
                    'salesperson_id' => $request->user()->id,
                    'created_by' => $request->user()->id,
                ]);

                foreach ($lineResults as $line) {
                    SaleItem::create(['sales_invoice_id' => $invoice->id, ...$line]);
                }

                $revenueAccount = ChartOfAccount::where('code', '4000')->firstOrFail();
                $cogsAccount = ChartOfAccount::where('code', '5000')->firstOrFail();
                $inventoryAccount = ChartOfAccount::where('code', '1200')->firstOrFail();
                $receivableAccount = ChartOfAccount::where('code', '1100')->firstOrFail();

                $journalLines = [];
                foreach ($data['payments'] ?? [] as $paymentData) {
                    $paymentMethod = PaymentMethod::findOrFail($paymentData['payment_method_id']);

                    SalePayment::create([
                        'sales_invoice_id' => $invoice->id,
                        'payment_method_id' => $paymentMethod->id,
                        'amount' => $paymentData['amount'],
                        'paid_at' => $data['sale_date'],
                        'reference_no' => $paymentData['reference_no'] ?? null,
                        'created_by' => $request->user()->id,
                    ]);

                    $journalLines[] = ['account_id' => $paymentMethod->chart_of_account_id, 'debit' => $paymentData['amount']];
                }

                if ($dueAmount > 0.005) {
                    $journalLines[] = ['account_id' => $receivableAccount->id, 'debit' => $dueAmount, 'party' => $customer];
                }

                $journalLines[] = ['account_id' => $revenueAccount->id, 'credit' => $total];

                if ($totalCost > 0) {
                    $journalLines[] = ['account_id' => $cogsAccount->id, 'debit' => $totalCost];
                    $journalLines[] = ['account_id' => $inventoryAccount->id, 'credit' => $totalCost];
                }

                $this->accounting->postEntry(
                    lines: $journalLines,
                    narration: "Sale {$invoice->invoice_number}".($customer ? " — {$customer->name}" : ''),
                    branchId: $branchId,
                    entryDate: $data['sale_date'],
                    reference: $invoice,
                    createdBy: $request->user()->id,
                );

                return $invoice;
            });
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['items' => [$e->getMessage()]]);
        }

        return new SalesInvoiceResource($invoice);
    }

    private function generateInvoiceNumber(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = 'SINV-'.now()->format('ymd').'-'.str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            if (! SalesInvoice::where('invoice_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        return 'SINV-'.now()->format('YmdHis').'-'.random_int(0, 9999);
    }
}
