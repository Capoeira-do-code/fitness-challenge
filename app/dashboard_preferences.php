<?php

declare(strict_types=1);

/**
 * Stable keys used by the remembered disclosures on Home.
 *
 * Keeping the allow-list on the server prevents arbitrary preference keys
 * from being written by the asynchronous state endpoint.
 *
 * @return list<string>
 */
function dashboard_panel_preference_keys(): array
{
    return [
        'dashboard.training-rank',
        'dashboard.training-progress',
        'dashboard.achievements',
        'dashboard.achievement-progress',
        'dashboard.quests-panel',
        'dashboard.season-panel',
        'dashboard.duels-panel',
        'dashboard.competitions-panel',
        'dashboard.weekly-panel',
        'dashboard.approvals',
        'dashboard.ranking-panel',
    ];
}

/**
 * Return every supported panel state. Missing rows intentionally resolve to
 * false so Home starts collapsed for new users and newly introduced panels.
 *
 * @return array<string, bool>
 */
function dashboard_panel_preferences(PDO $pdo, int $userId): array
{
    $states = array_fill_keys(dashboard_panel_preference_keys(), false);
    if ($userId <= 0) {
        return $states;
    }

    $rows = db_fetch_all(
        $pdo,
        'SELECT panel_key, expanded
         FROM user_dashboard_panel_preferences
         WHERE user_id = :user_id',
        [':user_id' => $userId]
    );
    foreach ($rows as $row) {
        $key = (string) ($row['panel_key'] ?? '');
        if (array_key_exists($key, $states)) {
            $states[$key] = (int) ($row['expanded'] ?? 0) === 1;
        }
    }

    return $states;
}

function save_dashboard_panel_preference(PDO $pdo, int $userId, string $panelKey, bool $expanded): void
{
    $panelKey = trim($panelKey);
    if ($userId <= 0 || !in_array($panelKey, dashboard_panel_preference_keys(), true)) {
        throw new InvalidArgumentException('Invalid dashboard panel preference.');
    }

    $updatedAt = now_iso();
    db_retry(static function () use ($pdo, $userId, $panelKey, $expanded, $updatedAt): void {
        $statement = $pdo->prepare(
            'INSERT INTO user_dashboard_panel_preferences (user_id, panel_key, expanded, updated_at)
             VALUES (:user_id, :panel_key, :expanded, :updated_at)
             ON CONFLICT(user_id, panel_key) DO UPDATE SET
                expanded = excluded.expanded,
                updated_at = excluded.updated_at'
        );
        $statement->execute([
            ':user_id' => $userId,
            ':panel_key' => $panelKey,
            ':expanded' => $expanded ? 1 : 0,
            ':updated_at' => $updatedAt,
        ]);
    });
}
