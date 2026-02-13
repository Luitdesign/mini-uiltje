<?php
$monthLabel = 'Feb 2026';
$leftToReview = 18;

$quickCategories = [
    'Boodschappen',
    'Vervoer',
    'Wonen',
    'Bankkosten',
    'Zorg',
    'Overig',
    'Uit eten',
    'Abonnementen',
];

$transactions = [
    [
        'id' => 1,
        'date' => '2026-02-12',
        'description' => 'Albert Heijn 1491 Zwolle NLD',
        'amount' => -12.46,
        'category' => null,
        'auto_category' => 'Boodschappen',
        'status' => 'auto',
        'note' => 'IBAN: NL85 XXXXX 181982 · Term: ZVW51 · Validatum: 12-02-2026',
    ],
    [
        'id' => 2,
        'date' => '2026-02-12',
        'description' => 'De Goudreinet ZwolleZ ZWOLLE NLD',
        'amount' => -1.69,
        'category' => null,
        'auto_category' => null,
        'status' => 'uncat',
        'note' => 'Pinbetaling · pas 1734',
    ],
    [
        'id' => 3,
        'date' => '2026-02-12',
        'description' => 'Salaris Februari',
        'amount' => 2650.00,
        'category' => 'Inkomen',
        'auto_category' => null,
        'status' => 'approved',
        'note' => 'Werkgever Mini-Uiltje BV',
    ],
    [
        'id' => 4,
        'date' => '2026-02-11',
        'description' => 'NS Reizen',
        'amount' => -7.40,
        'category' => null,
        'auto_category' => 'Vervoer',
        'status' => 'auto',
        'note' => 'OV-chipkaart opladen',
    ],
    [
        'id' => 5,
        'date' => '2026-02-11',
        'description' => 'Zilveren Kruis Premie',
        'amount' => -148.29,
        'category' => 'Zorg',
        'auto_category' => null,
        'status' => 'approved',
        'note' => 'Automatische incasso',
    ],
    [
        'id' => 6,
        'date' => '2026-02-10',
        'description' => 'Spotify',
        'amount' => -10.99,
        'category' => null,
        'auto_category' => null,
        'status' => 'uncat',
        'note' => 'Abonnement',
    ],
    [
        'id' => 7,
        'date' => '2026-02-10',
        'description' => 'ING Bankkosten',
        'amount' => -3.45,
        'category' => null,
        'auto_category' => 'Bankkosten',
        'status' => 'auto',
        'note' => 'Maandelijkse kosten',
    ],
    [
        'id' => 8,
        'date' => '2026-02-09',
        'description' => 'Huur Februari',
        'amount' => -925.00,
        'category' => 'Wonen',
        'auto_category' => null,
        'status' => 'approved',
        'note' => 'Woningcorporatie Delta',
    ],
    [
        'id' => 9,
        'date' => '2026-02-09',
        'description' => 'Etos Zwolle',
        'amount' => -16.35,
        'category' => null,
        'auto_category' => null,
        'status' => 'uncat',
        'note' => 'Drogist',
    ],
];

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
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Review · Mini-Uiltje</title>
    <style>
        :root {
            --bg: #0b0d10;
            --panel: #12161b;
            --panel-soft: #161b22;
            --border: #243040;
            --text: #e6edf3;
            --muted: #9aa4af;
            --accent: #6ee7b7;
            --accent-soft: rgba(110, 231, 183, 0.2);
            --warn: #e5aa58;
            --danger: #fb7185;
            --success: #6ee7b7;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: Inter, "Segoe UI", Roboto, sans-serif;
            background:
                radial-gradient(70% 60% at 20% 0%, rgba(110, 231, 183, 0.07), transparent 70%),
                radial-gradient(50% 40% at 80% 20%, rgba(148, 163, 184, 0.06), transparent 70%),
                var(--bg);
            color: var(--text);
        }

        .app {
            max-width: 640px;
            margin: 0 auto;
            min-height: 100vh;
            padding: 0 14px 170px;
        }

        .sticky-header {
            position: sticky;
            top: 0;
            z-index: 15;
            padding: 12px 0 12px;
            background: linear-gradient(180deg, rgba(11, 13, 16, 0.98), rgba(11, 13, 16, 0.86));
            backdrop-filter: blur(10px);
        }

        .top-row {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .back-btn,
        .month-pill,
        .chip,
        .ghost-btn,
        .action-btn,
        .cta-btn,
        .quick-chip,
        .nav-link,
        .more-btn,
        .approve-btn,
        .split-btn,
        .sheet-close,
        .sheet-category {
            border: 1px solid var(--border);
            background: var(--panel-soft);
            color: var(--text);
            border-radius: 999px;
            font: inherit;
        }

        .back-btn {
            width: 38px;
            height: 38px;
            border-radius: 11px;
            cursor: pointer;
        }

        h1 {
            margin: 0;
            font-size: clamp(1.6rem, 5vw, 2rem);
            flex: 1;
            text-align: center;
            transform: translateX(-19px);
        }

        .month-pill {
            padding: 8px 12px;
            border-radius: 12px;
            color: var(--text);
            cursor: pointer;
        }

        .progress-panel {
            margin-top: 12px;
            padding: 14px;
            border-radius: 14px;
            background: rgba(18, 22, 27, 0.9);
            border: 1px solid var(--border);
        }

        .progress-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .progress-top strong {
            font-size: 1.9rem;
            line-height: 1;
        }

        .progress-track {
            height: 10px;
            border-radius: 99px;
            background: rgba(148, 163, 184, 0.2);
            overflow: hidden;
        }

        .progress-fill {
            display: block;
            width: 58%;
            height: 100%;
            background: linear-gradient(90deg, #6ee7b7, #9ae6b4);
        }

        .filter-row {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            padding: 14px 2px 6px;
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        .filter-row::-webkit-scrollbar { display: none; }

        .chip {
            padding: 8px 14px;
            white-space: nowrap;
            cursor: pointer;
            color: var(--text);
        }

        .chip[aria-pressed="true"] {
            background: linear-gradient(180deg, rgba(110, 231, 183, 0.28), rgba(110, 231, 183, 0.16));
            border-color: var(--accent);
            color: var(--text);
        }

        .date-group {
            margin-top: 18px;
        }

        .date-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 0 2px 10px;
            color: var(--text);
        }

        .date-heading h2 {
            font-size: 1.65rem;
            margin: 0;
        }

        .date-heading span { color: var(--muted); }

        .card {
            background: linear-gradient(130deg, rgba(22, 27, 34, 0.95), rgba(18, 22, 27, 0.95));
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 14px;
            margin-bottom: 10px;
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.01);
        }

        .card-top {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 8px;
        }

        .mini-date {
            font-size: 0.95rem;
            color: var(--muted);
        }

        .amount {
            font-size: 2rem;
            margin: 0;
            font-weight: 700;
            letter-spacing: 0.2px;
        }

        .amount.negative { color: var(--danger); }
        .amount.positive { color: var(--success); }

        .description {
            margin: 8px 0 0;
            font-size: 1.45rem;
            line-height: 1.25;
        }

        .note {
            margin: 5px 0 12px;
            color: var(--muted);
            font-size: 0.95rem;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 7px 12px;
            border-radius: 999px;
            border: 1px solid transparent;
            font-weight: 600;
            margin-bottom: 10px;
            font-size: 0.95rem;
        }

        .pill-auto {
            background: linear-gradient(180deg, rgba(110, 231, 183, 0.3), rgba(110, 231, 183, 0.14));
            border-color: rgba(110, 231, 183, 0.45);
        }

        .pill-uncat {
            background: rgba(229, 170, 88, 0.18);
            border-color: rgba(229, 170, 88, 0.45);
            color: #f5c989;
        }

        .pill-approved {
            background: rgba(124, 233, 186, 0.15);
            border-color: rgba(124, 233, 186, 0.35);
            color: #baf4d8;
        }

        .quick-row {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 10px;
        }

        .quick-chip,
        .more-btn,
        .approve-btn,
        .split-btn,
        .sheet-category {
            padding: 6px 12px;
            border-radius: 11px;
            cursor: pointer;
            font-size: 0.98rem;
        }

        .more-btn,
        .split-btn { background: rgba(110, 231, 183, 0.08); }

        .actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .approve-btn {
            background: linear-gradient(180deg, rgba(110, 231, 183, 0.35), rgba(110, 231, 183, 0.18));
            border-color: rgba(110, 231, 183, 0.6);
            color: var(--text);
            font-weight: 600;
        }

        .split-btn { text-decoration: none; display: inline-block; }

        .sticky-footer {
            position: fixed;
            left: 0;
            right: 0;
            bottom: 64px;
            z-index: 18;
            display: flex;
            justify-content: center;
            padding: 10px 16px;
            pointer-events: none;
        }

        .cta-btn {
            pointer-events: auto;
            min-width: min(90vw, 340px);
            border-radius: 18px;
            padding: 12px 18px;
            font-weight: 700;
            background: linear-gradient(180deg, rgba(110, 231, 183, 0.38), rgba(110, 231, 183, 0.18));
            border-color: rgba(110, 231, 183, 0.6);
            cursor: pointer;
        }

        .sticky-footer[hidden] { display: none; }

        .bottom-nav {
            position: fixed;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 20;
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 8px;
            padding: 8px 12px calc(8px + env(safe-area-inset-bottom));
            background: rgba(18, 22, 27, 0.96);
            border-top: 1px solid var(--border);
            backdrop-filter: blur(9px);
        }

        .nav-link {
            border: none;
            background: transparent;
            padding: 4px 2px;
            text-align: center;
            color: var(--muted);
            font-size: 0.85rem;
            text-decoration: none;
        }

        .nav-link.active { color: var(--accent); font-weight: 700; }

        .nav-icon {
            display: block;
            font-size: 1.2rem;
            margin-bottom: 2px;
        }

        dialog.sheet {
            border: none;
            padding: 0;
            width: min(640px, 100vw);
            margin: auto auto 0;
            background: transparent;
            max-height: 80vh;
        }

        dialog.sheet::backdrop {
            background: rgba(4, 8, 16, 0.65);
            backdrop-filter: blur(2px);
        }

        .sheet-panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-bottom: none;
            border-radius: 20px 20px 0 0;
            padding: 14px;
        }

        .sheet-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .sheet-search {
            width: 100%;
            padding: 11px 12px;
            border-radius: 12px;
            border: 1px solid var(--border);
            background: #0e1217;
            color: var(--text);
            margin-bottom: 12px;
        }

        .sheet-section-title {
            margin: 12px 2px 8px;
            color: var(--muted);
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .sheet-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .toast {
            position: fixed;
            left: 50%;
            transform: translateX(-50%);
            bottom: 130px;
            z-index: 30;
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 10px 14px;
            color: var(--text);
            display: none;
            gap: 12px;
            align-items: center;
        }

        .toast.show { display: inline-flex; }

        .toast button {
            background: none;
            border: none;
            color: var(--accent);
            font-weight: 700;
            cursor: pointer;
        }
    </style>
</head>
<body>
<!-- TODO: connect common header include if needed later. -->
<main class="app" aria-label="Review transactions page">
    <header class="sticky-header">
        <div class="top-row">
            <button class="back-btn" aria-label="Go back">←</button>
            <h1>Review</h1>
            <button class="month-pill" type="button" aria-haspopup="listbox" aria-label="Select month">
                <?= htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8') ?> ▾
            </button>
        </div>
        <section class="progress-panel" aria-label="Review progress">
            <div class="progress-top">
                <strong><span id="left-count"><?= (int)$leftToReview ?></span> left</strong>
                <span class="muted">Needs review</span>
            </div>
            <div class="progress-track" role="progressbar" aria-valuenow="42" aria-valuemin="0" aria-valuemax="100">
                <span class="progress-fill"></span>
            </div>
        </section>
        <div class="filter-row" role="toolbar" aria-label="Review filters">
            <button class="chip" type="button" data-filter="needs" aria-pressed="true">Needs review</button>
            <button class="chip" type="button" data-filter="uncat" aria-pressed="false">Uncategorized</button>
            <button class="chip" type="button" data-filter="auto" aria-pressed="false">Auto suggested</button>
            <button class="chip" type="button" data-filter="all" aria-pressed="false">All</button>
        </div>
    </header>

    <?php foreach ($groupedTransactions as $date => $items): ?>
        <section class="date-group" data-date-group>
            <header class="date-heading">
                <h2><?= htmlspecialchars(format_date_label($date), ENT_QUOTES, 'UTF-8') ?> (<?= count($items) ?>)</h2>
                <span>Total <?= format_amount(array_sum(array_column($items, 'amount'))) ?></span>
            </header>

            <?php foreach ($items as $transaction): ?>
                <?php
                $status = $transaction['status'];
                $amountClass = $transaction['amount'] < 0 ? 'negative' : 'positive';
                $categoryText = $transaction['category'] ?? '';
                $autoCategory = $transaction['auto_category'] ?? '';
                ?>
                <article
                    class="card"
                    data-card
                    data-id="<?= (int)$transaction['id'] ?>"
                    data-status="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"
                    data-category="<?= htmlspecialchars($categoryText, ENT_QUOTES, 'UTF-8') ?>"
                    data-auto-category="<?= htmlspecialchars($autoCategory, ENT_QUOTES, 'UTF-8') ?>"
                >
                    <div class="card-top">
                        <span class="mini-date"><?= htmlspecialchars(format_date_label($transaction['date']), ENT_QUOTES, 'UTF-8') ?></span>
                        <p class="amount <?= $amountClass ?>"><?= format_amount((float)$transaction['amount']) ?></p>
                    </div>
                    <p class="description"><?= htmlspecialchars($transaction['description'], ENT_QUOTES, 'UTF-8') ?></p>
                    <?php if (!empty($transaction['note'])): ?>
                        <p class="note"><?= htmlspecialchars($transaction['note'], ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>

                    <div class="category-area" data-category-area></div>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>
</main>

<div class="sticky-footer" id="sticky-footer">
    <button class="cta-btn" id="approve-all-auto" type="button">✓ Approve all auto</button>
</div>

<nav class="bottom-nav" aria-label="Primary">
    <a href="#" class="nav-link"><span class="nav-icon">◔</span>Summary</a>
    <a href="#" class="nav-link active" aria-current="page"><span class="nav-icon">✅</span>Review</a>
    <a href="#" class="nav-link"><span class="nav-icon">✂️</span>Rules</a>
    <a href="#" class="nav-link"><span class="nav-icon">🐷</span>Savings</a>
    <a href="#" class="nav-link"><span class="nav-icon">⚙️</span>Settings</a>
</nav>

<dialog class="sheet" id="category-sheet" aria-label="Category picker">
    <div class="sheet-panel">
        <div class="sheet-head">
            <h2 style="margin:0">Choose category</h2>
            <button class="sheet-close" type="button" id="sheet-close">Close</button>
        </div>
        <label for="sheet-search" class="sr-only" style="position:absolute;left:-9999px">Search categories</label>
        <input id="sheet-search" class="sheet-search" type="search" placeholder="Search categories">

        <p class="sheet-section-title">Recent</p>
        <div class="sheet-list" id="sheet-recent">
            <?php foreach ($quickCategories as $category): ?>
                <button class="sheet-category" type="button" data-category-name="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>
                </button>
            <?php endforeach; ?>
        </div>

        <p class="sheet-section-title">All categories</p>
        <div class="sheet-list" id="sheet-all">
            <?php foreach ($quickCategories as $category): ?>
                <button class="sheet-category" type="button" data-category-name="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>
                </button>
            <?php endforeach; ?>
        </div>
    </div>
</dialog>

<div class="toast" id="toast" role="status" aria-live="polite">
    <span id="toast-message">Updated</span>
    <button type="button" id="toast-undo">Undo</button>
</div>

<script>
(() => {
    const quickCategories = <?= json_encode($quickCategories, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const chips = Array.from(document.querySelectorAll('.chip'));
    const cards = Array.from(document.querySelectorAll('[data-card]'));
    const groups = Array.from(document.querySelectorAll('[data-date-group]'));
    const cta = document.getElementById('sticky-footer');
    const approveAllAutoBtn = document.getElementById('approve-all-auto');
    const leftCount = document.getElementById('left-count');
    const sheet = document.getElementById('category-sheet');
    const sheetClose = document.getElementById('sheet-close');
    const sheetSearch = document.getElementById('sheet-search');
    const sheetButtons = Array.from(document.querySelectorAll('.sheet-category'));
    const toast = document.getElementById('toast');
    const toastMessage = document.getElementById('toast-message');
    const toastUndo = document.getElementById('toast-undo');

    let currentFilter = 'needs';
    let activeSheetCard = null;
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
                <div class="pill pill-auto">Auto: ${autoCategory || 'Suggested'} ✨</div>
                <div class="actions">
                    <button class="approve-btn" type="button" data-approve>✓ Approve</button>
                    <a class="split-btn" href="#" aria-label="Split transaction">✂ Split</a>
                </div>
            `;
        } else if (status === 'uncat') {
            const chipsMarkup = quickCategories.slice(0, 5).map((name) =>
                `<button type="button" class="quick-chip" data-select-category="${name}">${name}</button>`
            ).join('');

            area.innerHTML = `
                <div class="pill pill-uncat">Uncategorized ⚠️</div>
                <div class="quick-row">
                    ${chipsMarkup}
                    <button class="more-btn" type="button" data-open-sheet>+ More</button>
                </div>
                <div class="actions">
                    <a class="split-btn" href="#" aria-label="Split transaction">✂ Split</a>
                </div>
            `;
        } else {
            area.innerHTML = `
                <div class="pill pill-approved">${category || 'Approved'} ✓</div>
                <div class="actions">
                    <a class="split-btn" href="#" aria-label="Split transaction">✂ Split</a>
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

        const visibleAutoCount = cards.filter((card) => !card.hidden && card.dataset.status === 'auto').length;
        const showCta = currentFilter === 'needs' || currentFilter === 'auto';
        cta.hidden = !(showCta && visibleAutoCount > 0);

        const remaining = cards.filter((card) => statusNeedsReview(card.dataset.status)).length;
        leftCount.textContent = String(remaining);
    }

    function showToast(message, onUndo) {
        undoState = onUndo;
        toastMessage.textContent = message;
        toast.classList.add('show');

        window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(() => {
            toast.classList.remove('show');
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

        const moreBtn = event.target.closest('[data-open-sheet]');
        if (moreBtn) {
            activeSheetCard = moreBtn.closest('[data-card]');
            if (sheet && typeof sheet.showModal === 'function') sheet.showModal();
        }
    });

    approveAllAutoBtn.addEventListener('click', () => {
        const visibleAutoCards = cards.filter((card) => !card.hidden && card.dataset.status === 'auto');
        if (visibleAutoCards.length === 0) return;

        const previous = visibleAutoCards.map((card) => ({
            card,
            status: card.dataset.status,
            category: card.dataset.category,
        }));

        visibleAutoCards.forEach((card) => approveCard(card, false));
        showToast(`Approved ${visibleAutoCards.length} auto suggestions.`, () => {
            previous.forEach((item) => {
                item.card.dataset.status = item.status;
                item.card.dataset.category = item.category;
            });
            renderCards();
        });
    });

    sheetButtons.forEach((button) => {
        button.addEventListener('click', () => {
            if (activeSheetCard) {
                setCategory(activeSheetCard, button.dataset.categoryName || 'Overig');
            }
            if (sheet.open) sheet.close();
        });
    });

    sheetSearch.addEventListener('input', () => {
        const term = sheetSearch.value.trim().toLowerCase();
        sheetButtons.forEach((button) => {
            const match = button.dataset.categoryName.toLowerCase().includes(term);
            button.hidden = !match;
        });
    });

    sheetClose.addEventListener('click', () => {
        if (sheet.open) sheet.close();
    });

    sheet.addEventListener('click', (event) => {
        const panel = sheet.querySelector('.sheet-panel');
        if (panel && !panel.contains(event.target)) {
            sheet.close();
        }
    });

    toastUndo.addEventListener('click', () => {
        if (undoState) undoState();
        undoState = null;
        toast.classList.remove('show');
    });

    cards.forEach(formatCategoryArea);
    renderCards();
})();
</script>
<!-- TODO: connect common footer include if needed later. -->
</body>
</html>
