<?php

namespace App\Exports;

use App\Models\User;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Protection;

class LoanMigrationTemplateExport implements FromCollection, WithHeadings, ShouldAutoSize, WithEvents
{
    public function headings(): array
    {
        return [
            'member_id',
            'member_name',

            'guarantor_1_id',
            'guarantor_1_name',
            'guarantor_1_amount',

            'guarantor_2_id',
            'guarantor_2_name',
            'guarantor_2_amount',

            'original_principal',
            'interest_rate',
            'interest_amount',
            'net_disbursed',

            'number_of_installments',
            'paid_installments',
            'outstanding_principal',

            'issued_date',
            'due_date',
            'migration_date',

            'note',
        ];
    }

    public function collection(): Collection
    {
        /*
         * This template generates one row per member.
         *
         * Locked columns:
         * - member_id
         * - member_name
         * - guarantor_1_id
         * - guarantor_1_name
         * - guarantor_2_id
         * - guarantor_2_name
         * - interest_amount
         * - net_disbursed
         *
         * Editable columns:
         * - guarantor_1_amount
         * - guarantor_2_amount
         * - original_principal
         * - interest_rate
         * - number_of_installments
         * - paid_installments
         * - outstanding_principal
         * - issued_date
         * - due_date
         * - migration_date
         * - note
         *
         * If guarantors already exist in another table, later we can update this
         * query to prefill guarantor_1_id/name and guarantor_2_id/name.
         */

        $members = User::query()
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        return $members->map(function ($member) {
            return [
                $member->id,
                $member->name,

                '', // guarantor_1_id
                '', // guarantor_1_name
                '', // guarantor_1_amount

                '', // guarantor_2_id
                '', // guarantor_2_name
                '', // guarantor_2_amount

                '', // original_principal
                '', // interest_rate
                '', // interest_amount - formula added below
                '', // net_disbursed - formula added below

                '', // number_of_installments
                '', // paid_installments
                '', // outstanding_principal

                '', // issued_date
                '', // due_date
                '', // migration_date

                '', // note
            ];
        });
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                $highestRow = max(2, $sheet->getHighestRow());

                /*
                 * Column map:
                 *
                 * A = member_id
                 * B = member_name
                 * C = guarantor_1_id
                 * D = guarantor_1_name
                 * E = guarantor_1_amount
                 * F = guarantor_2_id
                 * G = guarantor_2_name
                 * H = guarantor_2_amount
                 * I = original_principal
                 * J = interest_rate
                 * K = interest_amount
                 * L = net_disbursed
                 * M = number_of_installments
                 * N = paid_installments
                 * O = outstanding_principal
                 * P = issued_date
                 * Q = due_date
                 * R = migration_date
                 * S = note
                 */

                $sheet->freezePane('A2');

                /*
                 * Header style.
                 */
                $sheet->getStyle('A1:S1')->getFont()->setBold(true);
                $sheet->getStyle('A1:S1')
                    ->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()
                    ->setARGB('FFE2E8F0');

                /*
                 * Protect the sheet.
                 */
                $sheet->getProtection()->setSheet(true);
                $sheet->getProtection()->setPassword('loan-migration');

                /*
                 * Lock all cells first.
                 */
                $sheet->getStyle("A1:S{$highestRow}")
                    ->getProtection()
                    ->setLocked(Protection::PROTECTION_PROTECTED);

                /*
                 * Unlock only editable columns from row 2 downward.
                 *
                 * E = guarantor_1_amount
                 * H = guarantor_2_amount
                 * I = original_principal
                 * J = interest_rate
                 * M = number_of_installments
                 * N = paid_installments
                 * O = outstanding_principal
                 * P = issued_date
                 * Q = due_date
                 * R = migration_date
                 * S = note
                 */
                foreach (['E', 'H', 'I', 'J', 'M', 'N', 'O', 'P', 'Q', 'R', 'S'] as $col) {
                    $sheet->getStyle("{$col}2:{$col}{$highestRow}")
                        ->getProtection()
                        ->setLocked(Protection::PROTECTION_UNPROTECTED);
                }

                /*
                 * Formula columns:
                 * K = interest_amount = original_principal * interest_rate / 100
                 * L = net_disbursed = original_principal - interest_amount
                 *
                 * These are locked, but calculated automatically.
                 */
                for ($row = 2; $row <= $highestRow; $row++) {
                    $sheet->setCellValue(
                        "K{$row}",
                        '=IF(OR(I' . $row . '="",J' . $row . '=""),"",ROUND(I' . $row . '*J' . $row . '/100,0))'
                    );

                    $sheet->setCellValue(
                        "L{$row}",
                        '=IF(OR(I' . $row . '="",K' . $row . '=""),"",I' . $row . '-K' . $row . ')'
                    );
                }

                /*
                 * Locked columns background.
                 */
                foreach (['A', 'B', 'C', 'D', 'F', 'G', 'K', 'L'] as $col) {
                    $sheet->getStyle("{$col}1:{$col}{$highestRow}")
                        ->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()
                        ->setARGB('FFF1F5F9');
                }

                /*
                 * Editable columns background.
                 */
                foreach (['E', 'H', 'I', 'J', 'M', 'N', 'O', 'P', 'Q', 'R', 'S'] as $col) {
                    $sheet->getStyle("{$col}1:{$col}{$highestRow}")
                        ->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()
                        ->setARGB('FFFFFFFF');
                }

                /*
                 * Number formatting.
                 */
                foreach (['E', 'H', 'I', 'K', 'L', 'O'] as $col) {
                    $sheet->getStyle("{$col}2:{$col}{$highestRow}")
                        ->getNumberFormat()
                        ->setFormatCode('#,##0');
                }

                $sheet->getStyle("J2:J{$highestRow}")
                    ->getNumberFormat()
                    ->setFormatCode('0.00');

                foreach (['P', 'Q', 'R'] as $col) {
                    $sheet->getStyle("{$col}2:{$col}{$highestRow}")
                        ->getNumberFormat()
                        ->setFormatCode('yyyy-mm-dd');
                }

                /*
                 * Header comments.
                 */
                $sheet->getComment('A1')->getText()->createTextRun('Locked. Member ID from the system. Do not edit.');
                $sheet->getComment('B1')->getText()->createTextRun('Locked. Member name from the system. Do not edit.');

                $sheet->getComment('C1')->getText()->createTextRun('Locked. Guarantor 1 ID from the system. Do not edit.');
                $sheet->getComment('D1')->getText()->createTextRun('Locked. Guarantor 1 name from the system. Do not edit.');
                $sheet->getComment('E1')->getText()->createTextRun('Editable. Enter guarantor 1 pledged amount only.');

                $sheet->getComment('F1')->getText()->createTextRun('Locked. Guarantor 2 ID from the system. Do not edit.');
                $sheet->getComment('G1')->getText()->createTextRun('Locked. Guarantor 2 name from the system. Do not edit.');
                $sheet->getComment('H1')->getText()->createTextRun('Editable. Enter guarantor 2 pledged amount only.');

                $sheet->getComment('I1')->getText()->createTextRun('Editable. Original loan principal before upfront interest deduction.');
                $sheet->getComment('J1')->getText()->createTextRun('Editable. Interest rate deducted upfront. Example: enter 5 for 5%.');

                $sheet->getComment('K1')->getText()->createTextRun('Auto-calculated. original_principal × interest_rate / 100.');
                $sheet->getComment('L1')->getText()->createTextRun('Auto-calculated. original_principal minus interest_amount.');

                $sheet->getComment('M1')->getText()->createTextRun('Editable. Number of installments. Maximum 12.');
                $sheet->getComment('N1')->getText()->createTextRun('Editable. Paid installments before migration. Cannot exceed number_of_installments.');
                $sheet->getComment('O1')->getText()->createTextRun('Editable. Remaining principal only. Interest was deducted upfront.');

                $sheet->getComment('P1')->getText()->createTextRun('Editable. Original issued date. Format: YYYY-MM-DD.');
                $sheet->getComment('Q1')->getText()->createTextRun('Editable. Next due date for remaining installment. Format: YYYY-MM-DD.');
                $sheet->getComment('R1')->getText()->createTextRun('Editable. Migration date. Optional. Format: YYYY-MM-DD.');
                $sheet->getComment('S1')->getText()->createTextRun('Editable. Optional note.');

                /*
                 * Data validation: interest_rate between 0 and 100.
                 */
                for ($row = 2; $row <= $highestRow; $row++) {
                    $validation = $sheet->getCell("J{$row}")->getDataValidation();
                    $validation->setType(DataValidation::TYPE_DECIMAL);
                    $validation->setErrorStyle(DataValidation::STYLE_STOP);
                    $validation->setAllowBlank(true);
                    $validation->setShowInputMessage(true);
                    $validation->setShowErrorMessage(true);
                    $validation->setErrorTitle('Invalid interest rate');
                    $validation->setError('Interest rate must be between 0 and 100.');
                    $validation->setPromptTitle('Interest rate');
                    $validation->setPrompt('Enter rate as a number. Example: 5 means 5%.');
                    $validation->setFormula1(0);
                    $validation->setFormula2(100);
                    $validation->setOperator(DataValidation::OPERATOR_BETWEEN);
                }

                /*
                 * Data validation: number_of_installments from 1 to 12.
                 */
                for ($row = 2; $row <= $highestRow; $row++) {
                    $validation = $sheet->getCell("M{$row}")->getDataValidation();
                    $validation->setType(DataValidation::TYPE_WHOLE);
                    $validation->setErrorStyle(DataValidation::STYLE_STOP);
                    $validation->setAllowBlank(true);
                    $validation->setShowInputMessage(true);
                    $validation->setShowErrorMessage(true);
                    $validation->setErrorTitle('Invalid installments');
                    $validation->setError('Number of installments must be between 1 and 12.');
                    $validation->setPromptTitle('Installments');
                    $validation->setPrompt('Enter a number from 1 to 12.');
                    $validation->setFormula1(1);
                    $validation->setFormula2(12);
                    $validation->setOperator(DataValidation::OPERATOR_BETWEEN);
                }

                /*
                 * Data validation: paid_installments from 0 to 12.
                 * Backend will also verify it does not exceed number_of_installments.
                 */
                for ($row = 2; $row <= $highestRow; $row++) {
                    $validation = $sheet->getCell("N{$row}")->getDataValidation();
                    $validation->setType(DataValidation::TYPE_WHOLE);
                    $validation->setErrorStyle(DataValidation::STYLE_STOP);
                    $validation->setAllowBlank(true);
                    $validation->setShowInputMessage(true);
                    $validation->setShowErrorMessage(true);
                    $validation->setErrorTitle('Invalid paid installments');
                    $validation->setError('Paid installments must be between 0 and 12.');
                    $validation->setPromptTitle('Paid installments');
                    $validation->setPrompt('Enter a number from 0 to 12.');
                    $validation->setFormula1(0);
                    $validation->setFormula2(12);
                    $validation->setOperator(DataValidation::OPERATOR_BETWEEN);
                }

                /*
                 * Alignments and filters.
                 */
                $sheet->setAutoFilter("A1:S{$highestRow}");

                $sheet->getStyle("A1:S{$highestRow}")
                    ->getAlignment()
                    ->setVertical('center');

                /*
                 * Make the note column wider.
                 */
                $sheet->getColumnDimension('S')->setWidth(35);
            },
        ];
    }
}