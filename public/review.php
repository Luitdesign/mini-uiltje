<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$userId = current_user_id();

$allowedReviewFilters = ['needs', 'uncat', 'auto', 'all'];
$currentReviewFilter = (string)($_GET['filter'] ?? 'needs');
if (!in_array($currentReviewFilter, $allowedReviewFilters, true)) {
    $currentReviewFilter = 'needs';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate($config);

    $postedReviewFilter = (string)($_POST['review_filter'] ?? 'needs');
    if (!in_array($postedReviewFilter, $allowedReviewFilters, true)) {
        $postedReviewFilter = 'needs';
    }

    $action = (string)($_POST['action'] ?? '');
    if ($action === 'approve_auto') {
        $txnId = (int)($_POST['transaction_id'] ?? 0);
        if ($txnId > 0) {
            $stmt = $db->prepare(
                "UPDATE transactions
                 SET approved = 1,
                     category_id = COALESCE(category_id, category_auto_id)
                 WHERE id = :id AND user_id = :uid"
            );
            $stmt->execute([
                ':id' => $txnId,
                ':uid' => $userId,
            ]);
        }
    }

    if ($action === 'set_category') {
        $txnId = (int)($_POST['transaction_id'] ?? 0);
        $categoryId = (int)($_POST['category_id'] ?? 0);
        if ($txnId > 0 && $categoryId > 0) {
            $stmt = $db->prepare(
                "UPDATE transactions
                 SET approved = 1,
                     category_id = :category_id
                 WHERE id = :id AND user_id = :uid"
            );
            $stmt->execute([
                ':category_id' => $categoryId,
                ':id' => $txnId,
                ':uid' => $userId,
            ]);
        }
    }

    if ($action === 'disapprove_auto') {
        $txnId = (int)($_POST['transaction_id'] ?? 0);
        if ($txnId > 0) {
            $stmt = $db->prepare(
                "UPDATE transactions
                 SET approved = 0,
                     category_id = NULL,
                     category_auto_id = NULL,
                     rule_auto_id = NULL,
                     auto_reason = NULL
                 WHERE id = :id AND user_id = :uid"
            );
            $stmt->execute([
                ':id' => $txnId,
                ':uid' => $userId,
            ]);
        }
    }

    if ($action === 'split_transaction') {
        $txnId = (int)($_POST['transaction_id'] ?? 0);
        $splitCount = (int)($_POST['split_count'] ?? 0);
        $amountsRaw = $_POST['split_amounts'] ?? [];
        $splitAmounts = [];

        if ($txnId > 0 && $splitCount >= 2 && $splitCount <= 3 && is_array($amountsRaw)) {
            $splitAmounts = array_fill(0, $splitCount, null);
            $missingIndex = null;

            for ($i = 0; $i < $splitCount; $i++) {
                $amountRaw = trim((string)($amountsRaw[$i] ?? ''));
                if ($amountRaw === '') {
                    if ($missingIndex !== null) {
                        $splitAmounts = [];
                        break;
                    }
                    $missingIndex = $i;
                    continue;
                }
                if (!is_numeric($amountRaw)) {
                    $splitAmounts = [];
                    break;
                }
                $splitAmounts[$i] = (float)$amountRaw;
            }

            if ($splitAmounts !== [] && $missingIndex !== null) {
                $transaction = repo_get_transaction($db, $userId, $txnId);
                if ($transaction) {
                    $originalAbs = round(abs((float)$transaction['amount_signed']), 2);
                    $sum = 0.0;
                    foreach ($splitAmounts as $amountValue) {
                        if ($amountValue !== null) {
                            $sum += (float)$amountValue;
                        }
                    }
                    $remaining = round($originalAbs - $sum, 2);
                    if ($remaining > 0) {
                        $splitAmounts[$missingIndex] = $remaining;
                    } else {
                        $splitAmounts = [];
                    }
                } else {
                    $splitAmounts = [];
                }
            }
        }

        if ($splitAmounts !== []) {
            try {
                repo_split_transaction($db, $userId, $txnId, array_values($splitAmounts));
            } catch (Throwable $e) {
                // Ignore split failures and return to review state.
            }
        }
    }

    redirect('/review.php?filter=' . urlencode($postedReviewFilter));
}

$assignableCategories = repo_list_assignable_categories($db);

$allCategories = array_map(
    static fn (array $category): array => [
        'id' => (int)$category['id'],
        'name' => (string)$category['name'],
    ],
    $assignableCategories
);

if ($allCategories === []) {
    $allCategories = [['id' => 0, 'name' => 'Overig']];
}

$stmt = $db->prepare(
    "SELECT
        t.id,
        t.txn_date,
        t.description,
        t.friendly_name,
        t.amount_signed,
        t.notes,
        c.name AS category_name,
        ca.name AS auto_category_name
     FROM transactions t
     LEFT JOIN categories c ON c.id = t.category_id
     LEFT JOIN categories ca ON ca.id = t.category_auto_id
     WHERE t.user_id = :uid
       AND t.is_split_active = 1
       AND t.approved = 0
     ORDER BY t.txn_date DESC, t.id DESC"
);
$stmt->execute([':uid' => $userId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$transactions = array_map(static function (array $row): array {
    $hasAutoCategory = !empty($row['auto_category_name']);
    $status = $hasAutoCategory ? 'auto' : 'uncat';

    return [
        'id' => (int)$row['id'],
        'date' => (string)$row['txn_date'],
        'description' => (string)$row['description'],
        'friendly_name' => $row['friendly_name'] !== null ? trim((string)$row['friendly_name']) : null,
        'amount' => (float)$row['amount_signed'],
        'category' => $row['category_name'] !== null ? (string)$row['category_name'] : null,
        'auto_category' => $row['auto_category_name'] !== null ? (string)$row['auto_category_name'] : null,
        'status' => $status,
        'note' => $row['notes'] !== null ? trim((string)$row['notes']) : null,
    ];
}, $rows);

$leftToReview = count($transactions);
$monthLabel = 'No pending transactions';
if ($transactions !== []) {
    $monthValue = DateTime::createFromFormat('Y-m-d', $transactions[0]['date']);
    if ($monthValue instanceof DateTime) {
        $monthLabel = $monthValue->format('M Y');
    }
}

usort($transactions, static function (array $a, array $b): int {
    return strcmp($b['date'], $a['date']);
});

$groupedTransactions = [];
foreach ($transactions as $transaction) {
    $groupedTransactions[$transaction['date']][] = $transaction;
}

function format_amount(float $amount): string
{
    $sign = $amount < 0 ? '-' : '+';
    return sprintf('%s€%s', $sign, number_format(abs($amount), 2, ',', '.'));
}

function format_date_label(string $date): string
{
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    return $dt ? $dt->format('M j') : $date;
}

render_header('Review · Mini-Uiltje', 'review');
?>

<section class="card review-summary" aria-label="Review progress">
    <h1>Review</h1>
    <p class="small">Month: <?= htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8') ?></p>
    <p><strong><span id="left-count"><?= (int)$leftToReview ?></span> left</strong> · <span class="small">Needs review</span></p>
    <div class="inline-actions review-filters" role="toolbar" aria-label="Review filters">
        <button class="btn chip" type="button" data-filter="needs" aria-pressed="true">Needs review</button>
        <button class="btn chip" type="button" data-filter="uncat" aria-pressed="false">Uncategorized</button>
        <button class="btn chip" type="button" data-filter="auto" aria-pressed="false">Auto suggested</button>
        <button class="btn chip" type="button" data-filter="all" aria-pressed="false">All</button>
    </div>
</section>

<div id="review-groups" class="review-groups">
<?php foreach ($groupedTransactions as $date => $items): ?>
    <section class="date-group review-date-group" data-date-group>
        <header class="row review-date-header">
            <h2><?= htmlspecialchars(format_date_label($date), ENT_QUOTES, 'UTF-8') ?> (<?= count($items) ?>)</h2>
            <span class="small">Total <?= format_amount(array_sum(array_column($items, 'amount'))) ?></span>
        </header>

        <?php foreach ($items as $transaction): ?>
            <?php
            $status = $transaction['status'];
            $categoryText = $transaction['category'] ?? '';
            $autoCategory = $transaction['auto_category'] ?? '';
            ?>
            <article
                class="card review-card"
                data-card
                data-id="<?= (int)$transaction['id'] ?>"
                data-has-friendly="<?= !empty($transaction['friendly_name']) ? '1' : '0' ?>"
                data-status="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"
                data-category="<?= htmlspecialchars($categoryText, ENT_QUOTES, 'UTF-8') ?>"
                data-auto-category="<?= htmlspecialchars($autoCategory, ENT_QUOTES, 'UTF-8') ?>"
                data-amount-abs="<?= number_format(abs((float)$transaction['amount']), 2, '.', '') ?>"
            >
                <div class="row review-card-header">
                    <span class="small"><?= htmlspecialchars(format_date_label($transaction['date']), ENT_QUOTES, 'UTF-8') ?></span>
                    <p>
                        <strong class="<?= $transaction['amount'] < 0 ? 'money-neg' : 'money-pos' ?>">
                            <?= format_amount((float)$transaction['amount']) ?>
                        </strong>
                    </p>
                </div>
                <?php if (!empty($transaction['friendly_name'])): ?>
                    <button type="button" class="txn-toggle review-friendly-toggle" data-friendly-toggle aria-expanded="false">
                        <strong><?= htmlspecialchars($transaction['friendly_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                    </button>
                    <div class="review-original" data-original-details hidden>
                        <p class="review-description"><?= htmlspecialchars($transaction['description'], ENT_QUOTES, 'UTF-8') ?></p>
                        <?php if (!empty($transaction['note'])): ?>
                            <p class="small review-note"><?= htmlspecialchars($transaction['note'], ENT_QUOTES, 'UTF-8') ?></p>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <p class="review-description"><?= htmlspecialchars($transaction['description'], ENT_QUOTES, 'UTF-8') ?></p>
                    <?php if (!empty($transaction['note'])): ?>
                        <p class="small review-note"><?= htmlspecialchars($transaction['note'], ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="category-area" data-category-area></div>

                <div class="txn-split" data-split-panel hidden>
                    <div class="txn-split-header">
                        <strong>Split transaction</strong>
                        <button type="button" class="txn-edit-link" data-split-close>Close</button>
                    </div>
                    <div class="review-split-fields">
                        <label>
                            Number of transactions
                            <select class="input js-split-count" name="split_count" form="split-form-<?= (int)$transaction['id'] ?>">
                                <option value="2">2</option>
                                <option value="3">3</option>
                            </select>
                        </label>
                        <div class="review-split-amounts">
                            <label>
                                Amount 1
                                <input class="input js-split-amount" type="number" inputmode="decimal" min="0.01" step="0.01" name="split_amounts[]" form="split-form-<?= (int)$transaction['id'] ?>" data-split-index="1">
                            </label>
                            <label>
                                Amount 2
                                <input class="input js-split-amount" type="number" inputmode="decimal" min="0.01" step="0.01" name="split_amounts[]" form="split-form-<?= (int)$transaction['id'] ?>" data-split-index="2">
                            </label>
                            <label>
                                Amount 3
                                <input class="input js-split-amount" type="number" inputmode="decimal" min="0.01" step="0.01" name="split_amounts[]" form="split-form-<?= (int)$transaction['id'] ?>" data-split-index="3" hidden>
                            </label>
                        </div>
                        <div class="inline-actions">
                            <button class="btn btn-split-action" type="submit" form="split-form-<?= (int)$transaction['id'] ?>">Split</button>
                        </div>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </section>
<?php endforeach; ?>

<?php foreach ($transactions as $transaction): ?>
    <form id="split-form-<?= (int)$transaction['id'] ?>" method="post" action="/review.php" style="display:none;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token($config), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="split_transaction">
        <input type="hidden" name="transaction_id" value="<?= (int)$transaction['id'] ?>">
        <input type="hidden" name="review_filter" value="<?= htmlspecialchars($currentReviewFilter, ENT_QUOTES, 'UTF-8') ?>" data-review-filter-input>
    </form>
<?php endforeach; ?>
</div>

<?php foreach ($transactions as $transaction): ?>
    <form id="disapprove-form-<?= (int)$transaction['id'] ?>" method="post" action="/review.php" style="display:none;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token($config), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="disapprove_auto">
        <input type="hidden" name="transaction_id" value="<?= (int)$transaction['id'] ?>">
        <input type="hidden" name="review_filter" value="<?= htmlspecialchars($currentReviewFilter, ENT_QUOTES, 'UTF-8') ?>" data-review-filter-input>
    </form>
    <form id="approve-form-<?= (int)$transaction['id'] ?>" method="post" action="/review.php" style="display:none;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token($config), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="approve_auto">
        <input type="hidden" name="transaction_id" value="<?= (int)$transaction['id'] ?>">
        <input type="hidden" name="review_filter" value="<?= htmlspecialchars($currentReviewFilter, ENT_QUOTES, 'UTF-8') ?>" data-review-filter-input>
    </form>
    <form id="set-category-form-<?= (int)$transaction['id'] ?>" method="post" action="/review.php" style="display:none;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token($config), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="set_category">
        <input type="hidden" name="transaction_id" value="<?= (int)$transaction['id'] ?>">
        <input type="hidden" name="category_id" value="">
        <input type="hidden" name="review_filter" value="<?= htmlspecialchars($currentReviewFilter, ENT_QUOTES, 'UTF-8') ?>" data-review-filter-input>
    </form>
<?php endforeach; ?>

<div class="card" id="toast" role="status" aria-live="polite" hidden>
    <span id="toast-message">Updated</span>
    <button class="btn" type="button" id="toast-undo">Undo</button>
</div>

<script>
(() => {
    const allCategories = <?= json_encode($allCategories, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const inlineCategoryLimit = 5;
    const chips = Array.from(document.querySelectorAll('.chip'));
    const cards = Array.from(document.querySelectorAll('[data-card]'));
    const groups = Array.from(document.querySelectorAll('[data-date-group]'));
    const leftCount = document.getElementById('left-count');
    const toast = document.getElementById('toast');
    const toastMessage = document.getElementById('toast-message');
    const toastUndo = document.getElementById('toast-undo');

    const allowedFilters = new Set(['needs', 'uncat', 'auto', 'all']);
    let currentFilter = <?= json_encode($currentReviewFilter, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    if (!allowedFilters.has(currentFilter)) {
        currentFilter = 'needs';
    }
    let undoState = null;
    let toastTimer = null;

    const statusNeedsReview = (status) => status === 'auto' || status === 'uncat';

    function formatCategoryArea(card) {
        const area = card.querySelector('[data-category-area]');
        const status = card.dataset.status;
        const category = card.dataset.category;
        const autoCategory = card.dataset.autoCategory;

        if (status === 'auto') {
            area.innerHTML = `
                <div class="badge badge-savings">Auto: ${autoCategory || 'Suggested'}</div>
                <div class="inline-actions">
                    <button class="btn" type="button" data-approve>Approve</button>
                    <button class="btn btn-danger" type="submit" form="disapprove-form-${card.dataset.id}">Disapprove</button>
                    <button class="btn btn-split-action" type="button" data-split-toggle>Split</button>
                </div>
            `;
        } else if (status === 'uncat') {
            const showAllCategories = card.dataset.showAllCategories === '1';
            const visibleCategories = allCategories.slice(0, inlineCategoryLimit);
            const hiddenCategories = allCategories.slice(inlineCategoryLimit);
            const chipsMarkup = visibleCategories.map((category) =>
                `<button type="button" class="btn" data-select-category-id="${category.id}">${category.name}</button>`
            ).join('');
            const extraMarkup = showAllCategories
                ? hiddenCategories.map((category) =>
                    `<button type="button" class="btn" data-select-category-id="${category.id}">${category.name}</button>`
                ).join('')
                : '';
            const toggleMarkup = hiddenCategories.length === 0
                ? ''
                : showAllCategories
                    ? '<button class="btn btn-more-toggle" type="button" data-hide-more>Show less</button>'
                    : '<button class="btn btn-more-toggle" type="button" data-show-more>+ More</button>';

            area.innerHTML = `
                <div class="badge">Uncategorized</div>
                <div class="inline-actions">
                    ${chipsMarkup}
                    ${toggleMarkup}
                </div>
                <div class="inline-actions">
                    ${extraMarkup}
                </div>
                <div class="inline-actions">
                    <button class="btn btn-split-action" type="button" data-split-toggle>Split</button>
                </div>
            `;
        } else {
            area.innerHTML = `
                <div class="badge badge-savings">${category || 'Approved'}</div>
                <div class="inline-actions">
                    <button class="btn btn-split-action" type="button" data-split-toggle>Split</button>
                </div>
            `;
        }
    }

    function renderCards() {
        cards.forEach((card) => {
            formatCategoryArea(card);
            const status = card.dataset.status;
            let visible = false;

            if (currentFilter === 'all') visible = true;
            if (currentFilter === 'needs') visible = statusNeedsReview(status);
            if (currentFilter === 'uncat') visible = status === 'uncat';
            if (currentFilter === 'auto') visible = status === 'auto';

            card.hidden = !visible;
        });

        groups.forEach((group) => {
            const visibleCards = group.querySelectorAll('[data-card]:not([hidden])').length;
            group.hidden = visibleCards === 0;
        });

        const remaining = cards.filter((card) => statusNeedsReview(card.dataset.status)).length;
        leftCount.textContent = String(remaining);
    }

    function showToast(message, onUndo) {
        undoState = onUndo;
        toastMessage.textContent = message;
        toast.hidden = false;

        window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(() => {
            toast.hidden = true;
            undoState = null;
        }, 3400);
    }

    function approveCard(card, showUndo = true) {
        const form = document.getElementById(`approve-form-${card.dataset.id}`);
        if (form) {
            form.submit();
        }
    }

    function setCategory(card, categoryId) {
        const form = document.getElementById(`set-category-form-${card.dataset.id}`);
        if (!form) return;

        const input = form.querySelector('input[name="category_id"]');
        if (input) {
            input.value = String(categoryId);
            form.submit();
        }
    }

    function toggleFriendlyDetails(card) {
        if (!card || card.dataset.hasFriendly !== '1') return;

        const details = card.querySelector('[data-original-details]');
        const friendlyToggleBtn = card.querySelector('[data-friendly-toggle]');
        if (!details || !friendlyToggleBtn) return;

        const isExpanded = !details.hidden;
        details.hidden = isExpanded;
        friendlyToggleBtn.setAttribute('aria-expanded', String(!isExpanded));
    }

    chips.forEach((chip) => {
        chip.addEventListener('click', () => {
            currentFilter = chip.dataset.filter;
            chips.forEach((btn) => btn.setAttribute('aria-pressed', String(btn === chip)));
            document.querySelectorAll('[data-review-filter-input]').forEach((input) => {
                input.value = currentFilter;
            });
            renderCards();
        });
    });

    chips.forEach((btn) => btn.setAttribute('aria-pressed', String(btn.dataset.filter === currentFilter)));
    document.querySelectorAll('[data-review-filter-input]').forEach((input) => {
        input.value = currentFilter;
    });

    document.addEventListener('click', (event) => {
        const splitToggleBtn = event.target.closest('[data-split-toggle]');
        if (splitToggleBtn) {
            const card = splitToggleBtn.closest('[data-card]');
            if (card) {
                const splitPanel = card.querySelector('[data-split-panel]');
                if (splitPanel) {
                    splitPanel.hidden = !splitPanel.hidden;
                }
            }
            return;
        }

        const splitCloseBtn = event.target.closest('[data-split-close]');
        if (splitCloseBtn) {
            const splitPanel = splitCloseBtn.closest('[data-split-panel]');
            if (splitPanel) {
                splitPanel.hidden = true;
            }
            return;
        }

        const approveBtn = event.target.closest('[data-approve]');
        if (approveBtn) {
            const card = approveBtn.closest('[data-card]');
            if (card) approveCard(card);
            return;
        }

        const quickCategoryBtn = event.target.closest('[data-select-category-id]');
        if (quickCategoryBtn) {
            const card = quickCategoryBtn.closest('[data-card]');
            const categoryId = Number(quickCategoryBtn.dataset.selectCategoryId || '0');
            if (card && Number.isInteger(categoryId) && categoryId > 0) {
                setCategory(card, categoryId);
            }
            return;
        }

        const showMoreBtn = event.target.closest('[data-show-more]');
        if (showMoreBtn) {
            const card = showMoreBtn.closest('[data-card]');
            if (card) {
                card.dataset.showAllCategories = '1';
                formatCategoryArea(card);
            }
            return;
        }

        const hideMoreBtn = event.target.closest('[data-hide-more]');
        if (hideMoreBtn) {
            const card = hideMoreBtn.closest('[data-card]');
            if (card) {
                card.dataset.showAllCategories = '0';
                formatCategoryArea(card);
            }
            return;
        }

        const friendlyToggleBtn = event.target.closest('[data-friendly-toggle]');
        if (friendlyToggleBtn) {
            const card = friendlyToggleBtn.closest('[data-card]');
            toggleFriendlyDetails(card);
            return;
        }

        const reviewCard = event.target.closest('[data-card]');
        if (reviewCard) {
            const interactiveTarget = event.target.closest('button, input, select, textarea, label, a');
            if (!interactiveTarget) {
                toggleFriendlyDetails(reviewCard);
            }
        }
    });

    const splitCountSelectors = Array.from(document.querySelectorAll('.js-split-count'));
    splitCountSelectors.forEach((select) => {
        const updateSplitInputs = () => {
            const splitPanel = select.closest('[data-split-panel]');
            if (!splitPanel) return;

            const splitCount = Number(select.value || '2');
            splitPanel.querySelectorAll('.js-split-amount').forEach((input) => {
                const index = Number(input.dataset.splitIndex || '0');
                const isActive = index <= splitCount;
                input.hidden = !isActive;
                if (!isActive) {
                    input.value = '';
                }
            });
        };

        const autofillRemainingAmount = () => {
            const card = select.closest('[data-card]');
            if (!card) return;
            const total = Number(card.dataset.amountAbs || '0');
            if (!Number.isFinite(total) || total <= 0) return;

            const splitPanel = select.closest('[data-split-panel]');
            if (!splitPanel) return;

            const inputs = Array.from(splitPanel.querySelectorAll('.js-split-amount')).filter((input) => !input.hidden);
            const emptyInputs = inputs.filter((input) => input.value.trim() === '');
            if (emptyInputs.length !== 1) return;

            const sumFilled = inputs.reduce((sum, input) => {
                if (input.value.trim() === '') return sum;
                const parsed = Number(input.value);
                return Number.isFinite(parsed) ? sum + parsed : sum;
            }, 0);

            const remaining = Math.round((total - sumFilled) * 100) / 100;
            if (remaining > 0) {
                emptyInputs[0].value = remaining.toFixed(2);
            }
        };

        select.addEventListener('change', () => {
            updateSplitInputs();
            autofillRemainingAmount();
        });

        const splitInputs = Array.from(select.closest('[data-split-panel]')?.querySelectorAll('.js-split-amount') ?? []);
        splitInputs.forEach((input) => {
            input.addEventListener('change', autofillRemainingAmount);
            input.addEventListener('blur', autofillRemainingAmount);
        });

        updateSplitInputs();
    });

    toastUndo.addEventListener('click', () => {
        if (undoState) undoState();
        undoState = null;
        toast.hidden = true;
    });

    cards.forEach(formatCategoryArea);
    renderCards();
})();
</script>

<?php render_footer(); ?>
