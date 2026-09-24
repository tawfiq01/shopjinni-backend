<?php

namespace App\Domain\Subscriptions\Http\Controllers\Admin;

use App\Domain\Companies\Models\Company;
use App\Domain\Subscriptions\Models\PaymentRecord;
use App\Domain\Subscriptions\Services\SubscriptionLifecycleService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentRecordController extends Controller
{
    public function __construct(private readonly SubscriptionLifecycleService $lifecycle) {}

    public function index()
    {
        return response()->json([
            'data' => PaymentRecord::with(['company', 'plan', 'recordedBy'])->orderByDesc('id')->limit(200)->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'company_id' => ['required', Rule::exists('companies', 'id')],
            'method' => ['required', Rule::in(['bkash', 'nagad', 'bank', 'cash', 'other'])],
            'reference' => ['nullable', 'string', 'max:255'],
            'billing_cycle' => ['required', Rule::in(['monthly', 'yearly'])],
            'notes' => ['nullable', 'string'],
        ]);

        Company::findOrFail($data['company_id']);

        $payment = $this->lifecycle->recordPayment(
            companyId: $data['company_id'],
            method: $data['method'],
            reference: $data['reference'] ?? null,
            billingCycle: $data['billing_cycle'],
            recordedBy: $request->user(),
            notes: $data['notes'] ?? null,
        );

        return response()->json($payment->load(['company', 'plan', 'recordedBy']), 201);
    }
}
