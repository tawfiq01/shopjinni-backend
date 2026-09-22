<?php

namespace App\Domain\Reports\Http\Controllers;

use App\Domain\Accounting\Models\PaymentMethod;
use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\ProductVariantColor;
use App\Domain\Customers\Models\Customer;
use App\Domain\Inventory\Models\ImeiUnit;
use App\Domain\Inventory\Models\InventoryStock;
use App\Domain\Purchasing\Models\Distributor;
use App\Domain\Sales\Models\SalesInvoice;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function summary(Request $request)
    {
        $canViewCost = $request->user()->can('reports.view-cost');
        $now = now();

        $todayInvoices = SalesInvoice::whereBetween('sale_date', [$now->copy()->startOfDay(), $now->copy()->endOfDay()])->get();
        $monthInvoices = SalesInvoice::whereBetween('sale_date', [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()])->get();

        $branchId = $request->integer('branch_id') ?: ($request->user()->branch_id ?? Branch::where('is_main', true)->value('id'));

        $lowStockSkus = ProductVariantColor::where('is_active', true)
            ->where('reorder_level', '>', 0)
            ->with('variant.model')
            ->get(['id', 'product_variant_id', 'reorder_level', 'imei_tracking_enabled']);
        $stockBySku = InventoryStock::where('branch_id', $branchId)
            ->whereIn('product_variant_color_id', $lowStockSkus->pluck('id'))
            ->pluck('quantity', 'product_variant_color_id');
        $imeiCounts = ImeiUnit::where('branch_id', $branchId)
            ->where('status', 'in_stock')
            ->whereIn('product_variant_color_id', $lowStockSkus->pluck('id'))
            ->selectRaw('product_variant_color_id, COUNT(*) as total')
            ->groupBy('product_variant_color_id')
            ->pluck('total', 'product_variant_color_id');

        $lowStockCount = $lowStockSkus->filter(function (ProductVariantColor $sku) use ($stockBySku, $imeiCounts) {
            $quantity = $sku->resolvedImeiTrackingEnabled()
                ? ($imeiCounts[$sku->id] ?? 0)
                : ($stockBySku[$sku->id] ?? 0);

            return $quantity <= $sku->reorder_level;
        })->count();

        $totalCustomerDue = Customer::where('is_active', true)->get()->sum(fn (Customer $c) => $c->currentBalance());
        $totalDistributorDue = Distributor::where('is_active', true)->get()->sum(fn (Distributor $d) => $d->currentBalance());
        $cashPositionTotal = PaymentMethod::with('account')->where('is_active', true)->get()
            ->sum(fn (PaymentMethod $method) => $method->account->balance());

        return response()->json([
            'today' => [
                'sales_total' => round((float) $todayInvoices->sum('total'), 2),
                'sales_count' => $todayInvoices->count(),
                ...($canViewCost ? ['profit' => round((float) $todayInvoices->sum('profit'), 2)] : []),
            ],
            'this_month' => [
                'sales_total' => round((float) $monthInvoices->sum('total'), 2),
                'sales_count' => $monthInvoices->count(),
                ...($canViewCost ? ['profit' => round((float) $monthInvoices->sum('profit'), 2)] : []),
            ],
            'low_stock_count' => $lowStockCount,
            'total_customer_due' => round((float) $totalCustomerDue, 2),
            'total_distributor_due' => round((float) $totalDistributorDue, 2),
            'cash_position_total' => round((float) $cashPositionTotal, 2),
        ]);
    }
}
