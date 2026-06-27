<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use Treasury\Database;
use Treasury\Services\AccountService;

$dryRun = in_array('--dry-run', $argv, true);
$pdo = Database::pdo();
$accounts = new AccountService();

$parentStmt = $pdo->prepare('SELECT id FROM treasury_accounts WHERE code = "2000" LIMIT 1');
$parentStmt->execute();
$parentAccountId = (int)$parentStmt->fetchColumn();

if ($parentAccountId <= 0) {
    fwrite(STDERR, "Parent account 2000 was not found. Run db_bootstrap.php first.\n");
    exit(1);
}

$rowsStmt = $pdo->prepare(
    'SELECT le.id AS ledger_entry_id,
            le.transaction_id,
            le.direction,
            le.amount,
            le.admin_id AS ledger_admin_id,
            t.transaction_uuid,
            t.source_type,
            t.transaction_type,
            t.description,
            t.metadata,
            paid_req.paid_by_admin_id AS paid_request_admin_id,
            reimb_req.paid_by_admin_id AS reimbursed_request_admin_id
       FROM treasury_ledger_entries le
       JOIN treasury_transactions t ON t.id = le.transaction_id
       LEFT JOIN treasury_payout_requests paid_req ON paid_req.paid_transaction_id = t.id
       LEFT JOIN treasury_payout_requests reimb_req ON reimb_req.reimbursement_transaction_id = t.id
      WHERE le.account_id = :parent_account_id
      ORDER BY le.id ASC'
);
$rowsStmt->execute(['parent_account_id' => $parentAccountId]);
$rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

$updated = 0;
$skipped = 0;
$details = [];

if (!$dryRun) {
    $pdo->beginTransaction();
}

try {
    foreach ($rows as $row) {
        $adminId = 0;

        if (!empty($row['paid_request_admin_id'])) {
            $adminId = (int)$row['paid_request_admin_id'];
        } elseif (!empty($row['reimbursed_request_admin_id'])) {
            $adminId = (int)$row['reimbursed_request_admin_id'];
        }

        if ($adminId <= 0 && !empty($row['metadata'])) {
            $metadata = json_decode((string)$row['metadata'], true);
            if (is_array($metadata)) {
                foreach (['paid_by_admin_id', 'reimbursed_admin_id'] as $key) {
                    if (!empty($metadata[$key])) {
                        $adminId = (int)$metadata[$key];
                        break;
                    }
                }
            }
        }

        if ($adminId <= 0 && !empty($row['ledger_admin_id'])) {
            $adminId = (int)$row['ledger_admin_id'];
        }

        if ($adminId <= 0) {
            $skipped++;
            $details[] = sprintf(
                'SKIP ledger entry %d / transaction %s: unable to determine admin.',
                (int)$row['ledger_entry_id'],
                (string)$row['transaction_uuid']
            );
            continue;
        }

        $adminCheck = $pdo->prepare('SELECT COUNT(*) FROM treasury_admins WHERE id = :id');
        $adminCheck->execute(['id' => $adminId]);
        if ((int)$adminCheck->fetchColumn() === 0) {
            $skipped++;
            $details[] = sprintf(
                'SKIP ledger entry %d / transaction %s: admin ID %d no longer exists.',
                (int)$row['ledger_entry_id'],
                (string)$row['transaction_uuid'],
                $adminId
            );
            continue;
        }

        $childAccountId = $accounts->ensureAdminPayableAccount($adminId);

        $details[] = sprintf(
            '%s ledger entry %d / transaction %s to admin payable account 2000:%d (%s %s GP).',
            $dryRun ? 'WOULD MOVE' : 'MOVE',
            (int)$row['ledger_entry_id'],
            (string)$row['transaction_uuid'],
            $adminId,
            (string)$row['direction'],
            number_format((int)$row['amount'])
        );

        if (!$dryRun) {
            $update = $pdo->prepare(
                'UPDATE treasury_ledger_entries
                    SET account_id = :child_account_id,
                        admin_id = :admin_id
                  WHERE id = :ledger_entry_id
                    AND account_id = :parent_account_id'
            );
            $update->execute([
                'child_account_id' => $childAccountId,
                'admin_id' => $adminId,
                'ledger_entry_id' => (int)$row['ledger_entry_id'],
                'parent_account_id' => $parentAccountId,
            ]);
        }

        $updated++;
    }

    if (!$dryRun) {
        $pdo->commit();
    }
} catch (Throwable $e) {
    if (!$dryRun && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

foreach ($details as $line) {
    echo $line . PHP_EOL;
}

echo PHP_EOL;
echo $dryRun ? 'Dry run complete.' : 'Repair complete.';
echo ' Moved: ' . $updated . '. Skipped: ' . $skipped . '.' . PHP_EOL;
