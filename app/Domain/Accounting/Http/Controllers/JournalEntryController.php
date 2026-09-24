<?php

namespace App\Domain\Accounting\Http\Controllers;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Services\AccountingService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class JournalEntryController extends Controller
{
    public function __construct(private readonly AccountingService $accounting) {}

    public function index()
    {
        $entries = JournalEntry::with('lines.account')
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json(['data' => $entries]);
    }

    public function store(Request $request)
    {
        $companyId = $request->user()->company_id;

        $data = $request->validate([
            'entry_date' => ['required', 'date'],
            'narration' => ['required', 'string', 'max:255'],
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', Rule::exists('chart_of_accounts', 'id')->where('company_id', $companyId)],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $entry = $this->accounting->postEntry(
                lines: $data['lines'],
                narration: $data['narration'],
                branchId: $data['branch_id'] ?? null,
                entryDate: $data['entry_date'],
                createdBy: $request->user()->id,
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['lines' => [$e->getMessage()]]);
        }

        return response()->json(['data' => $entry], 201);
    }
}
