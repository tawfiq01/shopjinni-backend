<?php

namespace App\Domain\Purchasing\Http\Controllers;

use App\Domain\Accounting\Models\ChartOfAccount;
use App\Domain\Accounting\Models\PaymentMethod;
use App\Domain\Accounting\Services\AccountingService;
use App\Domain\Branches\Models\Branch;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Purchasing\Http\Resources\PurchaseInvoiceResource;
use App\Domain\Purchasing\Models\Distributor;
use App\Domain\Purchasing\Models\PurchaseInvoice;
use App\Domain\Purchasing\Models\PurchaseItem;
use App\Domain\Purchasing\Models\PurchasePayment;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class PurchaseInvoiceController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly AccountingService $accounting,
    ) {}

    public function index(Request $request)
    {
        $query = PurchaseInvoice::with('distributor')->orderByDesc('purchase_date')->orderByDesc('id');

        if ($request->filled('distributor_id')) {
            $query->where('distributor_id', $request->integer('distributor_id'));
        }

        return PurchaseInvoiceResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function show(PurchaseInvoice $purchaseInvoice)
    {
        return new PurchaseInvoiceResource($purchaseInvoice);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'distributor_id' => ['required', 'exists:distributors,id'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'invoice_number' => ['nullable', 'string', 'max:255', 'unique:purchase_invoices,invoice_number'],
            'purchase_date' => ['required', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_color_id' => ['required', 'exists:product_variant_colors,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
            'items.*.tax' => ['nullable', 'numeric', 'min:0'],
            'items.*.warranty_months' => ['nullable', 'integer', 'min:0'],
            'items.*.imeis' => ['nullable', 'array'],
            'items.*.imeis.*.imei1' => ['required_with:items.*.imeis', 'string', 'max:32'],
            'items.*.imeis.*.imei2' => ['nullable', 'string', 'max:32'],
            'items.*.imeis.*.serial_number' => ['nullable', 'string', 'max:64'],
            'items.*.imeis.*.is_demo' => ['nullable', 'boolean'],
            'payments' => ['nullable', 'array'],
            'payments.*.payment_method_id' => ['required_with:payments', 'exists:payment_methods,id'],
            'payments.*.amount' => ['required_with:payments', 'numeric', 'min:0.01'],
            'payments.*.paid_at' => ['nullable', 'date'],
            'payments.*.reference_no' => ['nullable', 'string', 'max:255'],
        ]);

        $branchId = $data['branch_id'] ?? $request->user()->branch_id ?? Branch::where('is_main', true)->value('id');
        $distributor = Distributor::findOrFail($data['distributor_id']);

        $subtotal = 0;
        $discountTotal = 0;
        $taxTotal = 0;
        foreach ($data['items'] as $item) {
            $subtotal += $item['quantity'] * $item['unit_cost'];
            $discountTotal += $item['discount'] ?? 0;
            $taxTotal += $item['tax'] ?? 0;
        }
        $total = round($subtotal - $discountTotal + $taxTotal, 2);

        $paidAmount = round(collect($data['payments'] ?? [])->sum('amount'), 2);
        if ($paidAmount > $total) {
            throw ValidationException::withMessages([
                'payments' => ['Total payments cannot exceed the invoice total.'],
            ]);
        }

        try {
            $invoice = DB::transaction(function () use ($data, $branchId, $subtotal, $discountTotal, $taxTotal, $total, $paidAmount, $distributor, $request) {
                $invoice = PurchaseInvoice::create([
                    'branch_id' => $branchId,
                    'distributor_id' => $distributor->id,
                    'invoice_number' => $data['invoice_number'] ?? $this->generateInvoiceNumber(),
                    'purchase_date' => $data['purchase_date'],
                    'subtotal' => $subtotal,
                    'discount' => $discountTotal,
                    'tax' => $taxTotal,
                    'total' => $total,
                    'paid_amount' => $paidAmount,
                    'due_amount' => round($total - $paidAmount, 2),
                    'created_by' => $request->user()->id,
                ]);

                foreach ($data['items'] as $itemData) {
                    $lineTotal = round(
                        $itemData['quantity'] * $itemData['unit_cost'] - ($itemData['discount'] ?? 0) + ($itemData['tax'] ?? 0),
                        2
                    );

                    $purchaseItem = PurchaseItem::create([
                        'purchase_invoice_id' => $invoice->id,
                        'product_variant_color_id' => $itemData['product_variant_color_id'],
                        'quantity' => $itemData['quantity'],
                        'unit_cost' => $itemData['unit_cost'],
                        'discount' => $itemData['discount'] ?? 0,
                        'tax' => $itemData['tax'] ?? 0,
                        'line_total' => $lineTotal,
                        'warranty_months' => $itemData['warranty_months'] ?? null,
                        'remaining_quantity' => $itemData['quantity'],
                    ]);

                    $this->inventory->receivePurchaseStock(
                        purchaseItem: $purchaseItem,
                        branchId: $branchId,
                        imeis: $itemData['imeis'] ?? [],
                        createdBy: $request->user()->id,
                    );
                }

                $inventoryAccount = ChartOfAccount::where('code', '1200')->firstOrFail();
                $payableAccount = ChartOfAccount::where('code', '2000')->firstOrFail();

                $this->accounting->postEntry(
                    lines: [
                        ['account_id' => $inventoryAccount->id, 'debit' => $total],
                        ['account_id' => $payableAccount->id, 'credit' => $total, 'party' => $distributor],
                    ],
                    narration: "Purchase {$invoice->invoice_number} — {$distributor->name}",
                    branchId: $branchId,
                    entryDate: $data['purchase_date'],
                    reference: $invoice,
                    createdBy: $request->user()->id,
                );

                foreach ($data['payments'] ?? [] as $paymentData) {
                    $paymentMethod = PaymentMethod::findOrFail($paymentData['payment_method_id']);

                    PurchasePayment::create([
                        'purchase_invoice_id' => $invoice->id,
                        'payment_method_id' => $paymentMethod->id,
                        'amount' => $paymentData['amount'],
                        'paid_at' => $paymentData['paid_at'] ?? $data['purchase_date'],
                        'reference_no' => $paymentData['reference_no'] ?? null,
                        'created_by' => $request->user()->id,
                    ]);

                    $this->accounting->postEntry(
                        lines: [
                            ['account_id' => $payableAccount->id, 'debit' => $paymentData['amount'], 'party' => $distributor],
                            ['account_id' => $paymentMethod->chart_of_account_id, 'credit' => $paymentData['amount']],
                        ],
                        narration: "Payment for {$invoice->invoice_number} — {$distributor->name}",
                        branchId: $branchId,
                        entryDate: $paymentData['paid_at'] ?? $data['purchase_date'],
                        reference: $invoice,
                        createdBy: $request->user()->id,
                    );
                }

                return $invoice;
            });
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['items' => [$e->getMessage()]]);
        }

        return new PurchaseInvoiceResource($invoice);
    }

    private function generateInvoiceNumber(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = 'PINV-'.now()->format('ymd').'-'.str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            if (! PurchaseInvoice::where('invoice_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        return 'PINV-'.now()->format('YmdHis').'-'.random_int(0, 9999);
    }
}
