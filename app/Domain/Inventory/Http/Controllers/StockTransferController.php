<?php

namespace App\Domain\Inventory\Http\Controllers;

use App\Domain\Catalog\Models\ProductVariantColor;
use App\Domain\Inventory\Http\Resources\StockTransferResource;
use App\Domain\Inventory\Models\ImeiUnit;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Models\StockTransferItem;
use App\Domain\Inventory\Services\InventoryService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class StockTransferController extends Controller
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function index()
    {
        $transfers = StockTransfer::with('fromBranch', 'toBranch')
            ->orderByDesc('transfer_date')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return StockTransferResource::collection($transfers);
    }

    public function store(Request $request)
    {
        $companyId = $request->user()->company_id;

        $data = $request->validate([
            'from_branch_id' => ['required', 'different:to_branch_id', Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'to_branch_id' => ['required', Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'transfer_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_color_id' => ['required', Rule::exists('product_variant_colors', 'id')->where('company_id', $companyId)],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.imei_unit_id' => ['nullable', Rule::exists('imei_units', 'id')->where('company_id', $companyId)],
        ], [
            'from_branch_id.different' => 'The source and destination branch must be different.',
        ]);

        try {
            $transfer = DB::transaction(function () use ($data, $request) {
                $transfer = StockTransfer::create([
                    'from_branch_id' => $data['from_branch_id'],
                    'to_branch_id' => $data['to_branch_id'],
                    'transfer_date' => $data['transfer_date'],
                    'notes' => $data['notes'] ?? null,
                    'created_by' => $request->user()->id,
                ]);

                foreach ($data['items'] as $itemData) {
                    $sku = ProductVariantColor::findOrFail($itemData['product_variant_color_id']);
                    $quantity = (int) $itemData['quantity'];
                    $imeiUnitId = null;

                    if ($sku->resolvedImeiTrackingEnabled()) {
                        if ($quantity !== 1) {
                            throw new InvalidArgumentException('IMEI-tracked items must be transferred one at a time.');
                        }
                        if (empty($itemData['imei_unit_id'])) {
                            throw new InvalidArgumentException('Select the specific IMEI unit to transfer.');
                        }
                        $unit = ImeiUnit::findOrFail($itemData['imei_unit_id']);
                        if ($unit->product_variant_color_id !== $sku->id) {
                            throw new InvalidArgumentException('That IMEI does not belong to the selected SKU.');
                        }
                        $this->inventory->transferImeiUnit(
                            $unit,
                            $data['from_branch_id'],
                            $data['to_branch_id'],
                            $request->user()->id,
                        );
                        $imeiUnitId = $unit->id;
                    } else {
                        $this->inventory->transferQuantity(
                            $sku,
                            $data['from_branch_id'],
                            $data['to_branch_id'],
                            $quantity,
                            $request->user()->id,
                        );
                    }

                    StockTransferItem::create([
                        'stock_transfer_id' => $transfer->id,
                        'product_variant_color_id' => $sku->id,
                        'imei_unit_id' => $imeiUnitId,
                        'quantity' => $quantity,
                    ]);
                }

                return $transfer;
            });
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['items' => [$e->getMessage()]]);
        }

        return new StockTransferResource($transfer->load('items'));
    }
}
