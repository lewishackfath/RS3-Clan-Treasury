INSERT INTO treasury_apps (name, slug, description, is_active)
VALUES
('Manual Admin', 'manual_admin', 'Manual treasury administration actions', 1),
('Bingo', 'bingo', 'RS3 clan bingo treasury source', 1),
('Runes of Power', 'runes_of_power', 'Runes of Power treasury source', 1)
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), is_active = VALUES(is_active);

INSERT INTO treasury_accounts (code, name, account_type, normal_balance, is_system, is_active)
VALUES
('1000', 'Official Treasury', 'asset', 'debit', 1, 1),
('1100', 'Admin Held Funds', 'asset', 'debit', 1, 1),
('2000', 'Admin Reimbursements Payable', 'liability', 'credit', 1, 1),
('3000', 'Opening Balance Equity', 'equity', 'credit', 1, 1),
('4000', 'Income', 'income', 'credit', 1, 1),
('4100', 'Entry Fee Income', 'income', 'credit', 1, 1),
('4300', 'Clan Contributions', 'income', 'credit', 1, 1),
('5000', 'Expenses', 'expense', 'debit', 1, 1),
('5100', 'Prize Expenses', 'expense', 'debit', 1, 1),
('6000', 'General Expenses', 'expense', 'debit', 1, 1)
ON DUPLICATE KEY UPDATE name = VALUES(name), account_type = VALUES(account_type), normal_balance = VALUES(normal_balance);

UPDATE treasury_accounts child
JOIN treasury_accounts parent ON parent.code = '4000'
SET child.parent_account_id = parent.id
WHERE child.code IN ('4100','4300');

UPDATE treasury_accounts child
JOIN treasury_accounts parent ON parent.code = '5000'
SET child.parent_account_id = parent.id
WHERE child.code IN ('5100','6000');
