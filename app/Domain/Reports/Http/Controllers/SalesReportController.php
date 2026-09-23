<?php

namespace App\Domain\Reports\Http\Controllers;

use App\Domain\Sales\Models\SalesInvoice;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class SalesReportController extends Controller
{
    public function summary(Request $request)
    {
        $from = ($request->date('from') ?? now()->subDays(29))->startOfDay();
        $to = ($request->date('to') ?? now())->endOfDay();
        $canViewCost = $request->user()->can('reports.view-cost');

        // Bounds are full timestamps (not just 'Y-m-d' strings) so this
        // compares correctly regardless of whether the DB stores DATE
        // columns with or without a time component (SQLite adds one).
        $invoices = SalesInvoice::whereBetween('sale_date', [$from, $to])
            ->with(['items.sku.variant.model.brand', 'items.sku.color', 'salesperson', 'customer'])
            ->get();

        $byDay = $invoices->groupBy(fn ($invoice) => $invoice->sale_date->toDateString())
            ->map(fn ($group, $date) => [
                'date' => $date,
                'total' => round((float) $group->sum('total'), 2),
                'count' => $group->count(),
            ])
            ->sortBy('date')
            ->values();

        $allItems = $invoices->flatMap(fn ($invoice) => $invoice->items);

        $byModel = $allItems->groupBy(fn ($item) => $item->sku->variant->model->id)
            ->map(function ($items) {
                $model = $items->first()->sku->variant->model;

                return [
                    'model_name' => "{$model->brand->name} {$model->name}",
                    'quantity' => $items->sum('quantity'),
                    'total' => round((float) $items->sum('line_total'), 2),
                ];
            })
            ->sortByDesc('total')
            ->values();

        $byColor = $allItems->groupBy(fn ($item) => $item->sku->color->id)
            ->map(function ($items) {
                return [
                    'color_name' => $items->first()->sku->color->name,
                    'quantity' => $items->sum('quantity'),
                    'total' => round((float) $items->sum('line_total'), 2),
                ];
            })
            ->sortByDesc('total')
            ->values();

        $bySalesperson = $invoices->groupBy(fn ($invoice) => $invoice->salesperson_id ?? 0)
            ->map(fn ($group) => [
                'name' => $group->first()->salesperson->name ?? 'Unassigned',
                'total' => round((float) $group->sum('total'), 2),
                'count' => $group->count(),
            ])
            ->sortByDesc('total')
            ->values();

        $byCustomer = $invoices->groupBy(fn ($invoice) => $invoice->customer_id ?? 0)
            ->map(fn ($group) => [
                'name' => $group->first()->customer->name ?? 'Walk-in',
                'total' => round((float) $group->sum('total'), 2),
                'count' => $group->count(),
            ])
            ->sortByDesc('total')
            ->values();

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'invoice_count' => $invoices->count(),
            'total_sales' => round((float) $invoices->sum('total'), 2),
            'total_discount' => round((float) $invoices->sum('discount'), 2),
            'total_due' => round((float) $invoices->sum('due_amount'), 2),
            ...($canViewCost ? ['total_profit' => round((float) $invoices->sum('profit'), 2)] : []),
            'by_day' => $byDay,
            'by_model' => $byModel,
            'by_color' => $byColor,
            'by_salesperson' => $bySalesperson,
            'by_customer' => $byCustomer,
        ]);
    }

    /**
     * Line-item-level sales listing for a date range — one row per sale_item,
     * unlike summary() which only ever returns aggregates.
     */
    public function details(Request $request)
    {
        $canViewCost = $request->user()->can('reports.view-cost');
        [$from, $to] = $this->dateRange($request);

        $rows = $this->detailRows($from, $to, $canViewCost);

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'total_quantity' => $rows->sum('quantity'),
            'total_sales' => round((float) $rows->sum('line_total'), 2),
            ...($canViewCost ? ['total_profit' => round((float) $rows->sum('profit'), 2)] : []),
            'rows' => $rows->values(),
        ]);
    }

    /**
     * Same rows as details(), streamed as a downloadable .xlsx workbook.
     */
    public function export(Request $request)
    {
        $canViewCost = $request->user()->can('reports.view-cost');
        [$from, $to] = $this->dateRange($request);

        $rows = $this->detailRows($from, $to, $canViewCost);

        $headers = ['Invoice #', 'Date', 'Customer', 'Salesperson', 'Branch', 'Product', 'SKU', 'IMEI', 'Demo', 'Qty', 'Unit Price', 'Discount', 'Line Total'];
        if ($canViewCost) {
            $headers[] = 'Unit Cost';
            $headers[] = 'Profit';
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Sales Details');
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:'.$sheet->getHighestColumn().'1')->getFont()->setBold(true);

        $rowNumber = 2;
        foreach ($rows as $row) {
            $values = [
                $row['invoice_number'],
                $row['sale_date'],
                $row['customer'] ?? '-',
                $row['salesperson'] ?? '-',
                $row['branch'] ?? '-',
                $row['display_name'],
                $row['sku'],
                $row['imei'] ?? '-',
                $row['is_demo'] ? 'Yes' : 'No',
                $row['quantity'],
                $row['unit_price'],
                $row['discount'],
                $row['line_total'],
            ];
            if ($canViewCost) {
                $values[] = $row['unit_cost'];
                $values[] = $row['profit'];
            }
            $sheet->fromArray($values, null, 'A'.$rowNumber);
            $rowNumber++;
        }

        foreach (range('A', $sheet->getHighestColumn()) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $filename = sprintf('sales-details_%s_to_%s.xlsx', $from->toDateString(), $to->toDateString());

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * @return array{0: \Illuminate\Support\Carbon, 1: \Illuminate\Support\Carbon}
     */
    private function dateRange(Request $request): array
    {
        // Bounds are full timestamps (not just 'Y-m-d' strings) so this
        // compares correctly regardless of whether the DB stores DATE
        // columns with or without a time component (SQLite adds one).
        $from = ($request->date('from') ?? now()->subDays(29))->startOfDay();
        $to = ($request->date('to') ?? now())->endOfDay();

        return [$from, $to];
    }

    private function detailRows($from, $to, bool $canViewCost): Collection
    {
        $invoices = SalesInvoice::whereBetween('sale_date', [$from, $to])
            ->with([
                'items.sku.variant.model.brand',
                'items.sku.color',
                'items.imeiUnit',
                'salesperson',
                'customer',
                'branch',
            ])
            ->orderBy('sale_date')
            ->get();

        return $invoices->flatMap(function (SalesInvoice $invoice) use ($canViewCost) {
            return $invoice->items->map(function ($item) use ($invoice, $canViewCost) {
                $model = $item->sku->variant->model;

                return [
                    'invoice_number' => $invoice->invoice_number,
                    'sale_date' => $invoice->sale_date->toDateString(),
                    'customer' => $invoice->customer?->name,
                    'salesperson' => $invoice->salesperson?->name,
                    'branch' => $invoice->branch?->name,
                    'sku' => $item->sku->sku,
                    'display_name' => sprintf(
                        '%s %s %s (%s)',
                        $model->brand->name,
                        $model->name,
                        $item->sku->variant->label(),
                        $item->sku->color->name,
                    ),
                    'imei' => $item->imeiUnit?->imei1,
                    'is_demo' => $item->imeiUnit?->is_demo ?? false,
                    'quantity' => $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'discount' => (float) $item->discount,
                    'line_total' => (float) $item->line_total,
                    ...($canViewCost ? [
                        'unit_cost' => (float) $item->unit_cost,
                        'profit' => (float) $item->profit,
                    ] : []),
                ];
            });
        });
    }
}
