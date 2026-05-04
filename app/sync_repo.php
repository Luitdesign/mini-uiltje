<?php
declare(strict_types=1);

function sync_connect_target_db(array $targetConfig): PDO {
    $host = trim((string)($targetConfig['host'] ?? ''));
    $name = trim((string)($targetConfig['name'] ?? ''));
    $user = (string)($targetConfig['user'] ?? '');
    $pass = (string)($targetConfig['pass'] ?? '');
    $charset = trim((string)($targetConfig['charset'] ?? 'utf8mb4'));

    if ($host === '' || $name === '' || $user === '') {
        throw new RuntimeException('Target database host, name, and user are required.');
    }

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $host, $name, $charset);

    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function sync_push_configuration_to_target(PDO $sourceDb, PDO $targetDb, array $parts): array {
    $allowedParts = ['savings', 'categories', 'settings', 'rules'];
    $selectedParts = array_values(array_intersect($allowedParts, $parts));

    if (count($selectedParts) === 0) {
        throw new RuntimeException('Select at least one configuration part to push.');
    }

    $result = [
        'savings' => 0,
        'categories' => 0,
        'settings' => 0,
        'rules' => 0,
    ];

    $savings = [];
    $categories = [];
    $settings = [];
    $rules = [];

    if (in_array('savings', $selectedParts, true) || in_array('categories', $selectedParts, true)) {
        $stmt = $sourceDb->query('SELECT id, name, active, sort_order, start_amount, monthly_amount, topup_category_id FROM savings ORDER BY id ASC');
        $savings = $stmt->fetchAll();
    }

    if (in_array('categories', $selectedParts, true)) {
        $stmt = $sourceDb->query('SELECT id, name, explainer, color, parent_id, is_parent, savings_id, created_at FROM categories ORDER BY id ASC');
        $categories = $stmt->fetchAll();
    }

    if (in_array('settings', $selectedParts, true)) {
        $stmt = $sourceDb->query('SELECT setting_key, setting_value FROM app_settings ORDER BY setting_key ASC');
        $settings = $stmt->fetchAll();
    }

    if (in_array('rules', $selectedParts, true)) {
        $stmt = $sourceDb->query(
            'SELECT r.id, u.username, r.active, r.priority, r.name, r.from_text, r.from_text_match, r.from_iban, r.mededelingen_text, r.mededelingen_match, r.rekening_equals, r.amount_min, r.amount_max, r.target_category_id, r.created_at
             FROM rules r
             INNER JOIN users u ON u.id = r.user_id
             ORDER BY r.id ASC'
        );
        $rules = $stmt->fetchAll();
    }

    $targetDb->beginTransaction();

    try {
        $targetDb->exec('SET FOREIGN_KEY_CHECKS=0');

        if (in_array('rules', $selectedParts, true)) {
            $targetDb->exec('DELETE FROM rules');
        }

        if (in_array('categories', $selectedParts, true)) {
            $targetDb->exec('DELETE FROM categories');
        }

        if (in_array('savings', $selectedParts, true)) {
            $targetDb->exec('DELETE FROM savings');
        }

        if (in_array('settings', $selectedParts, true)) {
            $targetDb->exec('DELETE FROM app_settings');
        }

        if (in_array('savings', $selectedParts, true)) {
            $insertSavings = $targetDb->prepare(
                'INSERT INTO savings (id, name, active, sort_order, start_amount, monthly_amount, topup_category_id)
                 VALUES (:id, :name, :active, :sort_order, :start_amount, :monthly_amount, :topup_category_id)'
            );

            foreach ($savings as $savingsRow) {
                $insertSavings->execute([
                    ':id' => (int)$savingsRow['id'],
                    ':name' => (string)$savingsRow['name'],
                    ':active' => (int)$savingsRow['active'],
                    ':sort_order' => (int)$savingsRow['sort_order'],
                    ':start_amount' => $savingsRow['start_amount'],
                    ':monthly_amount' => $savingsRow['monthly_amount'],
                    ':topup_category_id' => $savingsRow['topup_category_id'] !== null ? (int)$savingsRow['topup_category_id'] : null,
                ]);
                $result['savings']++;
            }
        }

        if (in_array('categories', $selectedParts, true)) {
            $insertCategories = $targetDb->prepare(
                'INSERT INTO categories (id, name, explainer, color, parent_id, is_parent, savings_id, created_at)
                 VALUES (:id, :name, :explainer, :color, :parent_id, :is_parent, :savings_id, :created_at)'
            );

            foreach ($categories as $categoryRow) {
                $insertCategories->execute([
                    ':id' => (int)$categoryRow['id'],
                    ':name' => (string)$categoryRow['name'],
                    ':explainer' => $categoryRow['explainer'] !== null ? (string)$categoryRow['explainer'] : null,
                    ':color' => $categoryRow['color'] !== null ? (string)$categoryRow['color'] : null,
                    ':parent_id' => $categoryRow['parent_id'] !== null ? (int)$categoryRow['parent_id'] : null,
                    ':is_parent' => (int)$categoryRow['is_parent'],
                    ':savings_id' => $categoryRow['savings_id'] !== null ? (int)$categoryRow['savings_id'] : null,
                    ':created_at' => (string)$categoryRow['created_at'],
                ]);
                $result['categories']++;
            }
        }

        if (in_array('settings', $selectedParts, true)) {
            $insertSetting = $targetDb->prepare(
                'INSERT INTO app_settings (setting_key, setting_value) VALUES (:setting_key, :setting_value)'
            );

            foreach ($settings as $settingRow) {
                $insertSetting->execute([
                    ':setting_key' => (string)$settingRow['setting_key'],
                    ':setting_value' => $settingRow['setting_value'],
                ]);
                $result['settings']++;
            }
        }

        if (in_array('rules', $selectedParts, true)) {
            $targetUsersStmt = $targetDb->query('SELECT id, username FROM users');
            $targetUsers = $targetUsersStmt->fetchAll();
            $targetUserMap = [];
            foreach ($targetUsers as $targetUser) {
                $targetUserMap[(string)$targetUser['username']] = (int)$targetUser['id'];
            }

            $missingUsers = [];
            foreach ($rules as $ruleRow) {
                $ruleUsername = (string)$ruleRow['username'];
                if (!isset($targetUserMap[$ruleUsername])) {
                    $missingUsers[$ruleUsername] = true;
                }
            }

            if (count($missingUsers) > 0) {
                throw new RuntimeException('Cannot copy rules because target DB is missing users: ' . implode(', ', array_keys($missingUsers)) . '.');
            }

            $insertRule = $targetDb->prepare(
                'INSERT INTO rules (id, user_id, active, priority, name, from_text, from_text_match, from_iban, mededelingen_text, mededelingen_match, rekening_equals, amount_min, amount_max, target_category_id, created_at)
                 VALUES (:id, :user_id, :active, :priority, :name, :from_text, :from_text_match, :from_iban, :mededelingen_text, :mededelingen_match, :rekening_equals, :amount_min, :amount_max, :target_category_id, :created_at)'
            );

            foreach ($rules as $ruleRow) {
                $insertRule->execute([
                    ':id' => (int)$ruleRow['id'],
                    ':user_id' => $targetUserMap[(string)$ruleRow['username']],
                    ':active' => (int)$ruleRow['active'],
                    ':priority' => (int)$ruleRow['priority'],
                    ':name' => (string)$ruleRow['name'],
                    ':from_text' => $ruleRow['from_text'],
                    ':from_text_match' => $ruleRow['from_text_match'],
                    ':from_iban' => $ruleRow['from_iban'],
                    ':mededelingen_text' => $ruleRow['mededelingen_text'],
                    ':mededelingen_match' => $ruleRow['mededelingen_match'],
                    ':rekening_equals' => $ruleRow['rekening_equals'],
                    ':amount_min' => $ruleRow['amount_min'],
                    ':amount_max' => $ruleRow['amount_max'],
                    ':target_category_id' => $ruleRow['target_category_id'] !== null ? (int)$ruleRow['target_category_id'] : null,
                    ':created_at' => (string)$ruleRow['created_at'],
                ]);
                $result['rules']++;
            }
        }

        $targetDb->exec('SET FOREIGN_KEY_CHECKS=1');
        $targetDb->commit();
    } catch (Throwable $e) {
        if ($targetDb->inTransaction()) {
            $targetDb->rollBack();
        }
        try {
            $targetDb->exec('SET FOREIGN_KEY_CHECKS=1');
        } catch (Throwable $_) {
            // Ignore best-effort foreign-key reset.
        }
        throw $e;
    }

    return $result;
}
