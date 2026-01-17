<?php
declare(strict_types=1);

function render_category_select(string $name, array $catsForSelect, ?int $selectedId): string {
    $groups = ['expense'=>[], 'income'=>[], 'transfer'=>[]];
    foreach ($catsForSelect as $c) {
        $groups[$c['type']][] = $c;
    }

    $html = '<select name="' . h($name) . '">';
    $html .= '<option value="">-- none --</option>';
    $labels = ['expense'=>'Expense', 'income'=>'Income', 'transfer'=>'Transfer'];
    foreach ($labels as $type => $lab) {
        if (empty($groups[$type])) continue;
        $html .= '<optgroup label="' . h($lab) . '">';
        foreach ($groups[$type] as $c) {
            $id = (int)$c['id'];
            $sel = ($selectedId !== null && $id === $selectedId) ? ' selected' : '';
            $html .= '<option value="' . $id . '"' . $sel . '>' . h($c['label']) . '</option>';
        }
        $html .= '</optgroup>';
    }
    $html .= '</select>';
    return $html;
}
