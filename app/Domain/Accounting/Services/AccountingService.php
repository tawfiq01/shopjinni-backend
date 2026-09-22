<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\ChartOfAccount;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AccountingService
{
    /**
     * Post a balanced double-entry journal entry.
     *
     * @param  array<int, array{account_id: int, debit?: float, credit?: float, party?: Model|null}>  $lines
     */
    public function postEntry(
        array $lines,
        string $narration,
        ?int $branchId = null,
        ?string $entryDate = null,
        ?Model $reference = null,
        ?int $createdBy = null,
    ): JournalEntry {
        if (count($lines) < 2) {
            throw new InvalidArgumentException('A journal entry needs at least two lines.');
        }

        $totalDebitCents = 0;
        $totalCreditCents = 0;

        foreach ($lines as $line) {
            $debitCents = (int) round(((float) ($line['debit'] ?? 0)) * 100);
            $creditCents = (int) round(((float) ($line['credit'] ?? 0)) * 100);

            if (($debitCents > 0) === ($creditCents > 0)) {
                throw new InvalidArgumentException(
                    'Each journal line must be either a debit or a credit, not both or neither.'
                );
            }

            $totalDebitCents += $debitCents;
            $totalCreditCents += $creditCents;
        }

        if ($totalDebitCents !== $totalCreditCents) {
            throw new InvalidArgumentException(sprintf(
                'Journal entry is not balanced: debit %.2f vs credit %.2f.',
                $totalDebitCents / 100,
                $totalCreditCents / 100,
            ));
        }

        return DB::transaction(function () use ($lines, $narration, $branchId, $entryDate, $reference, $createdBy) {
            $entry = JournalEntry::create([
                'branch_id' => $branchId,
                'entry_date' => $entryDate ?? now()->toDateString(),
                'narration' => $narration,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'created_by' => $createdBy,
            ]);

            foreach ($lines as $line) {
                $party = $line['party'] ?? null;
                $entry->lines()->create([
                    'chart_of_account_id' => $line['account_id'],
                    'debit' => $line['debit'] ?? 0,
                    'credit' => $line['credit'] ?? 0,
                    'party_type' => $party?->getMorphClass(),
                    'party_id' => $party?->getKey(),
                ]);
            }

            return $entry->load('lines.account');
        });
    }

    /**
     * Chronological ledger for one account, with a running balance in the
     * account's own normal-balance direction.
     */
    public function ledgerForAccount(ChartOfAccount $account, ?string $from = null, ?string $to = null): array
    {
        $query = $account->lines()->with('entry')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->when($from, fn ($q) => $q->where('journal_entries.entry_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('journal_entries.entry_date', '<=', $to))
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_lines.id')
            ->select('journal_lines.*');

        $isDebitNormal = $account->isDebitNormal();
        $running = 0.0;

        return $query->get()->map(function ($line) use (&$running, $isDebitNormal) {
            $delta = $isDebitNormal ? $line->debit - $line->credit : $line->credit - $line->debit;
            $running += (float) $delta;

            return [
                'id' => $line->id,
                'date' => $line->entry->entry_date->toDateString(),
                'narration' => $line->entry->narration,
                'debit' => (float) $line->debit,
                'credit' => (float) $line->credit,
                'running_balance' => round($running, 2),
            ];
        })->all();
    }

    /**
     * Subledger for a customer or distributor (all lines posted against them,
     * regardless of which control account was used).
     */
    public function ledgerForParty(Model $party): array
    {
        $lines = JournalLine::query()
            ->where('party_type', $party->getMorphClass())
            ->where('party_id', $party->getKey())
            ->with(['entry', 'account'])
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_lines.id')
            ->select('journal_lines.*')
            ->get();

        $running = 0.0;

        return $lines->map(function ($line) use (&$running) {
            // Receivable/payable control accounts are debit-normal (AR) or
            // credit-normal (AP); use the account's own convention.
            $isDebitNormal = $line->account->isDebitNormal();
            $delta = $isDebitNormal ? $line->debit - $line->credit : $line->credit - $line->debit;
            $running += (float) $delta;

            return [
                'id' => $line->id,
                'date' => $line->entry->entry_date->toDateString(),
                'narration' => $line->entry->narration,
                'account' => $line->account->name,
                'debit' => (float) $line->debit,
                'credit' => (float) $line->credit,
                'running_balance' => round($running, 2),
            ];
        })->all();
    }
}
