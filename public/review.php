<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_login();

$userId = current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate($config);

    $action = (string)($_POST['action'] ?? '');
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

    redirect('/review.php');
}

$allCategories = array_map(
    static fn (array $category): string => (string)$category['name'],
    repo_list_assignable_categories($db)
);

if ($allCategories === []) {
    $allCategories = ['Overig'];
}

$stmt = $db->prepare(
    "SELECT
        t.id,
        t.txn_date,
        t.description,
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
                data-status="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"
                data-category="<?= htmlspecialchars($categoryText, ENT_QUOTES, 'UTF-8') ?>"
                data-auto-category="<?= htmlspecialchars($autoCategory, ENT_QUOTES, 'UTF-8') ?>"
            >
                <div class="row review-card-header">
                    <span class="small"><?= htmlspecialchars(format_date_label($transaction['date']), ENT_QUOTES, 'UTF-8') ?></span>
                    <p>
                        <strong class="<?= $transaction['amount'] < 0 ? 'money-neg' : 'money-pos' ?>">
                            <?= format_amount((float)$transaction['amount']) ?>
                        </strong>
                    </p>
                </div>
                <p class="review-description"><?= htmlspecialchars($transaction['description'], ENT_QUOTES, 'UTF-8') ?></p>
                <?php if (!empty($transaction['note'])): ?>
                    <p class="small review-note"><?= htmlspecialchars($transaction['note'], ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>

                <div class="category-area" data-category-area></div>
            </article>
        <?php endforeach; ?>
    </section>
<?php endforeach; ?>
</div>

<?php foreach ($transactions as $transaction): ?>
    <form id="disapprove-form-<?= (int)$transaction['id'] ?>" method="post" action="/review.php" style="display:none;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token($config), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="disapprove_auto">
        <input type="hidden" name="transaction_id" value="<?= (int)$transaction['id'] ?>">
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

    let currentFilter = 'needs';
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
                    <a class="btn btn-split-action" href="#" aria-label="Split transaction">Split</a>
                </div>
            `;
        } else if (status === 'uncat') {
            const showAllCategories = card.dataset.showAllCategories === '1';
            const visibleCategories = allCategories.slice(0, inlineCategoryLimit);
            const hiddenCategories = allCategories.slice(inlineCategoryLimit);
            const chipsMarkup = visibleCategories.map((name) =>
                `<button type="button" class="btn" data-select-category="${name}">${name}</button>`
            ).join('');
            const extraMarkup = showAllCategories
                ? hiddenCategories.map((name) =>
                    `<button type="button" class="btn" data-select-category="${name}">${name}</button>`
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
                    <a class="btn btn-split-action" href="#" aria-label="Split transaction">Split</a>
                </div>
            `;
        } else {
            area.innerHTML = `
                <div class="badge badge-savings">${category || 'Approved'}</div>
                <div class="inline-actions">
                    <a class="btn btn-split-action" href="#" aria-label="Split transaction">Split</a>
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
        const prevStatus = card.dataset.status;
        const prevCategory = card.dataset.category;
        const nextCategory = card.dataset.autoCategory || 'Overig';

        card.dataset.status = 'approved';
        card.dataset.category = nextCategory;
        renderCards();

        if (showUndo) {
            showToast('Transaction approved.', () => {
                card.dataset.status = prevStatus;
                card.dataset.category = prevCategory;
                renderCards();
            });
        }
    }

    function setCategory(card, categoryName) {
        const prevStatus = card.dataset.status;
        const prevCategory = card.dataset.category;

        card.dataset.status = 'approved';
        card.dataset.category = categoryName;
        renderCards();

        showToast(`Category set to ${categoryName}.`, () => {
            card.dataset.status = prevStatus;
            card.dataset.category = prevCategory;
            renderCards();
        });
    }

    chips.forEach((chip) => {
        chip.addEventListener('click', () => {
            currentFilter = chip.dataset.filter;
            chips.forEach((btn) => btn.setAttribute('aria-pressed', String(btn === chip)));
            renderCards();
        });
    });

    document.addEventListener('click', (event) => {
        const approveBtn = event.target.closest('[data-approve]');
        if (approveBtn) {
            const card = approveBtn.closest('[data-card]');
            if (card) approveCard(card);
            return;
        }

        const quickCategoryBtn = event.target.closest('[data-select-category]');
        if (quickCategoryBtn) {
            const card = quickCategoryBtn.closest('[data-card]');
            if (card) setCategory(card, quickCategoryBtn.dataset.selectCategory || 'Overig');
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
        }
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
