<?php

namespace App\Imports;

use App\Models\Loan;
use App\Models\User;
use App\Services\LoanMigrationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class LoanMigrationImport implements ToArray, WithHeadingRow
{
    protected int $createdBy = 0;

    protected int $created = 0;

    protected int $skipped = 0;

    protected array $errors = [];

    public function __construct(
        protected LoanMigrationService $loanMigrationService
    ) {}

    public function setCreatedBy(int $createdBy): void
    {
        $this->createdBy = $createdBy;
    }

    public function array(array $rows): void
    {
        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;

            try {
                $data = $this->normalizeRow($row);

                // Skip members where finance did not enter loan data.
                if (
                    empty($data['original_principal']) &&
                    empty($data['number_of_installments']) &&
                    empty($data['paid_installments']) &&
                    empty($data['outstanding_principal']) &&
                    empty($data['issued_date']) &&
                    empty($data['due_date'])
                ) {
                    continue;
                }

                $validator = Validator::make($data, [
                    'member_id' => ['required', 'integer', 'exists:users,id'],

                    'original_principal' => ['required', 'numeric', 'min:0.01'],
                    'number_of_installments' => ['required', 'integer', 'min:1', 'max:12'],
                    'paid_installments' => ['required', 'integer', 'min:0'],
                    'outstanding_principal' => ['required', 'numeric', 'min:0'],

                    'issued_date' => ['required', 'date'],
                    'due_date' => ['required', 'date'],
                    'migration_date' => ['nullable', 'date'],

                    'note' => ['nullable', 'string', 'max:1000'],
                ]);

                if ($validator->fails()) {
                    $this->addError($rowNumber, $data, $validator->errors()->all());
                    continue;
                }

                $originalPrincipal = round((float) $data['original_principal'], 2);
                $numberOfInstallments = (int) $data['number_of_installments'];
                $paidInstallments = (int) $data['paid_installments'];
                $outstandingPrincipal = round((float) $data['outstanding_principal'], 2);

                if ($paidInstallments > $numberOfInstallments) {
                    $this->addError($rowNumber, $data, [
                        'paid_installments cannot exceed number_of_installments.',
                    ]);
                    continue;
                }

                $principalPaidBeforeMigration = round(
                    $originalPrincipal - $outstandingPrincipal,
                    2
                );

                if ($principalPaidBeforeMigration < 0) {
                    $this->addError($rowNumber, $data, [
                        'Outstanding principal cannot exceed original principal.',
                    ]);
                    continue;
                }

                $member = User::find($data['member_id']);

                if (! $member) {
                    $this->addError($rowNumber, $data, ['Member was not found.']);
                    continue;
                }

                $existingActiveLoan = Loan::query()
                    ->where('user_id', $member->id)
                    ->whereIn('status', ['pending', 'approved', 'active'])
                    ->exists();

                if ($existingActiveLoan) {
                    $this->addError($rowNumber, $data, [
                        'Member already has a pending, approved, or active loan.',
                    ]);
                    continue;
                }

                DB::transaction(function () use (
                    $member,
                    $data,
                    $originalPrincipal,
                    $numberOfInstallments,
                    $paidInstallments,
                    $outstandingPrincipal,
                    $principalPaidBeforeMigration
                ) {
                    $remainingInstallments = max(0, $numberOfInstallments - $paidInstallments);

                    $monthlyInstallment = $remainingInstallments > 0
                        ? round($outstandingPrincipal / $remainingInstallments, 2)
                        : null;

                    $loan = Loan::create([
                        'user_id' => $member->id,
                        'beneficiary_id' => null,

                        'principal' => $originalPrincipal,

                        // Interest was already deducted upfront before migration.
                        'interest_rate' => 0,
                        'total_payable' => $outstandingPrincipal,

                        'duration_months' => max(1, $remainingInstallments),
                        'issued_date' => $data['issued_date'],
                        'due_date' => $data['due_date'],

                        'status' => $outstandingPrincipal > 0 ? 'active' : 'completed',
                        'repayment_mode' => 'installment',
                        'monthly_installment' => $monthlyInstallment,

                        'approved_by' => $this->createdBy,
                        'is_migrated' => false,
                    ]);

                    $this->loanMigrationService->migrateLoan(
                        loan: $loan,
                        data: [
                            'issued_date' => $data['issued_date'],
                            'due_date' => $data['due_date'],
                            'migration_date' => $data['migration_date'] ?: $data['issued_date'],

                            'original_principal' => $originalPrincipal,
                            'number_of_installments' => $numberOfInstallments,
                            'paid_installments' => $paidInstallments,
                            'outstanding_principal' => $outstandingPrincipal,
                            'principal_paid_before_migration' => $principalPaidBeforeMigration,

                            'note' => $data['note'] ?? null,
                        ],
                        createdBy: $this->createdBy
                    );
                });

                $this->created++;
            } catch (InvalidArgumentException $e) {
                $this->addError($rowNumber, $row, [$e->getMessage()]);
            } catch (\Throwable $e) {
                $this->addError($rowNumber, $row, [$e->getMessage()]);
            }
        }
    }

    protected function normalizeRow(array $row): array
    {
        return [
            'member_id' => $row['member_id'] ?? null,
            'member_name' => $row['member_name'] ?? null,
            'phone' => $row['phone'] ?? null,
            'email' => $row['email'] ?? null,

            'original_principal' => $row['original_principal'] ?? null,
            'number_of_installments' => $row['number_of_installments'] ?? null,
            'paid_installments' => $row['paid_installments'] ?? 0,
            'outstanding_principal' => $row['outstanding_principal'] ?? null,

            'issued_date' => $this->dateValue($row['issued_date'] ?? null),
            'due_date' => $this->dateValue($row['due_date'] ?? null),
            'migration_date' => $this->dateValue($row['migration_date'] ?? null),

            'note' => $row['note'] ?? null,
        ];
    }

    protected function dateValue($value): ?string
    {
        if (! $value) {
            return null;
        }

        if (is_numeric($value)) {
            return Carbon::instance(
                ExcelDate::excelToDateTimeObject($value)
            )->toDateString();
        }

        return Carbon::parse($value)->toDateString();
    }

    protected function addError(int $rowNumber, array $data, array $errors): void
    {
        $this->skipped++;

        $this->errors[] = [
            'row' => $rowNumber,
            'member_id' => $data['member_id'] ?? null,
            'member_name' => $data['member_name'] ?? null,
            'errors' => $errors,
        ];
    }

    public function summary(): array
    {
        return [
            'created' => $this->created,
            'skipped' => $this->skipped,
            'errors_count' => count($this->errors),
        ];
    }

    public function errors(): array
    {
        return $this->errors;
    }
}