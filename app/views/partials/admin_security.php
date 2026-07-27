<?php

declare(strict_types=1);

$securitySettings = is_array($securitySettings ?? null) ? (array) $securitySettings : [];
$securitySummary = is_array($securitySummary ?? null) ? (array) $securitySummary : [];
$securityLogs = array_values((array) ($securityLogs ?? []));
$securityBlocks = array_values((array) ($securityBlocks ?? []));
$securityIpFilter = trim((string) ($securityIpFilter ?? ''));
$securityAllowedHosts = array_values((array) ($securitySettings['allowed_hosts'] ?? []));
$securityHostsEnvironmentManaged = (string) ($securitySettings['allowed_hosts_source'] ?? 'admin') === 'environment';
$securityHost = security_request_host((array) ($config ?? []));
$securityCurrentHost = (string) ($securityHost['host'] ?? '');
$securityFormatDate = static function (mixed $value): string {
    $raw = trim((string) $value);
    if ($raw === '') {
        return '—';
    }
    try {
        $date = new DateTimeImmutable($raw);

        return format_date_eu($date->format('Y-m-d')) . ' · ' . $date->format('H:i:s');
    } catch (Throwable) {
        return $raw;
    }
};
?>
<article class="panel settings-panel active admin-security-page" data-spa-section="security">
    <section class="admin-security-hero">
        <span class="admin-security-hero-icon" aria-hidden="true"><?= activity_icon_svg('shield') ?></span>
        <div>
            <p class="eyebrow"><?= e(t('admin.group_system')) ?></p>
            <h2><?= e(t('security.title')) ?></h2>
            <p><?= e(t('security.subtitle')) ?></p>
        </div>
        <span class="admin-security-state is-<?= !empty($securitySettings['auto_block']) ? 'active' : 'inactive' ?>">
            <?= e(!empty($securitySettings['auto_block']) ? t('security.auto_block_active') : t('security.auto_block_inactive')) ?>
        </span>
    </section>

    <section class="admin-security-stats" aria-label="<?= e(t('security.last_24_hours')) ?>">
        <span><strong><?= (int) ($securitySummary['total'] ?? 0) ?></strong><small><?= e(t('security.requests_24h')) ?></small></span>
        <span><strong><?= (int) ($securitySummary['unique_ips'] ?? 0) ?></strong><small><?= e(t('security.unique_ips')) ?></small></span>
        <span class="<?= (int) ($securitySummary['suspicious'] ?? 0) > 0 ? 'is-warning' : '' ?>"><strong><?= (int) ($securitySummary['suspicious'] ?? 0) ?></strong><small><?= e(t('security.suspicious')) ?></small></span>
        <span class="<?= (int) ($securitySummary['active_blocks'] ?? 0) > 0 ? 'is-danger' : '' ?>"><strong><?= (int) ($securitySummary['active_blocks'] ?? 0) ?></strong><small><?= e(t('security.active_blocks')) ?></small></span>
    </section>

    <div class="admin-security-grid">
        <section class="admin-security-card">
            <header>
                <span aria-hidden="true"><?= activity_icon_svg('globe') ?></span>
                <div><h3><?= e(t('security.domain_protection')) ?></h3><p><?= e(t('security.domain_protection_hint')) ?></p></div>
                <b class="<?= !empty($securitySettings['host_enforced']) ? 'is-ok' : 'is-warning' ?>">
                    <?= e(!empty($securitySettings['host_enforced']) ? t('security.enforced') : t('security.not_enforced')) ?>
                </b>
            </header>
            <form method="post" action="/?page=admin&amp;section=security" class="admin-security-settings-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="update_security_settings">
                <label>
                    <span><?= e(t('security.allowed_hosts')) ?></span>
                    <textarea name="allowed_hosts" rows="4" spellcheck="false" autocapitalize="none" placeholder="fitness.example.com&#10;www.fitness.example.com" <?= $securityHostsEnvironmentManaged ? 'readonly' : '' ?>><?= e(implode("\n", $securityAllowedHosts)) ?></textarea>
                    <small>
                        <?= e($securityHostsEnvironmentManaged ? t('security.hosts_env_managed') : t('security.allowed_hosts_hint')) ?>
                        <?php if ($securityCurrentHost !== ''): ?> <?= e(t('security.current_host', ['host' => $securityCurrentHost])) ?><?php endif; ?>
                    </small>
                </label>
                <div class="admin-security-settings-row">
                    <label class="admin-security-toggle">
                        <span><strong><?= e(t('security.auto_block')) ?></strong><small><?= e(t('security.auto_block_hint')) ?></small></span>
                        <span class="admin-app-switch"><input type="checkbox" name="auto_block" value="1" <?= !empty($securitySettings['auto_block']) ? 'checked' : '' ?>><i aria-hidden="true"></i></span>
                    </label>
                    <label>
                        <span><?= e(t('security.retention_days')) ?></span>
                        <input type="number" name="retention_days" value="<?= (int) ($securitySettings['retention_days'] ?? 90) ?>" min="7" max="365">
                        <small><?= e(t('security.retention_hint')) ?></small>
                    </label>
                </div>
                <button class="btn btn-primary" type="submit" <?= $securityHostsEnvironmentManaged ? 'title="' . e(t('security.hosts_env_managed')) . '"' : '' ?>><?= e(t('common.save')) ?></button>
            </form>
        </section>

        <section class="admin-security-card">
            <header>
                <span aria-hidden="true"><?= activity_icon_svg('lock') ?></span>
                <div><h3><?= e(t('security.active_blocks')) ?></h3><p><?= e(t('security.active_blocks_hint')) ?></p></div>
                <b><?= count($securityBlocks) ?></b>
            </header>
            <?php if ($securityBlocks === []): ?>
                <div class="admin-security-empty"><span aria-hidden="true">✓</span><p><?= e(t('security.no_active_blocks')) ?></p></div>
            <?php else: ?>
                <div class="admin-security-block-list">
                    <?php foreach ($securityBlocks as $securityBlock): ?>
                        <article>
                            <span><strong><?= e((string) ($securityBlock['ip_address'] ?? '')) ?></strong><small><?= e((string) ($securityBlock['reason'] ?? '')) ?></small></span>
                            <time><?= e(t('security.blocked_until', ['date' => $securityFormatDate($securityBlock['blocked_until'] ?? '')])) ?></time>
                            <form method="post" action="/?page=admin&amp;section=security">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="security_unblock_ip">
                                <input type="hidden" name="ip_address" value="<?= e((string) ($securityBlock['ip_address'] ?? '')) ?>">
                                <button class="btn btn-ghost small" type="submit"><?= e(t('security.unblock')) ?></button>
                            </form>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <section class="admin-security-log">
        <header>
            <div><h3><?= e(t('security.access_log')) ?></h3><p><?= e(t('security.access_log_hint')) ?></p></div>
            <?php if ($securityIpFilter !== ''): ?>
                <a class="btn btn-ghost small" href="/?page=admin&amp;section=security" data-spa-link><?= e(t('security.clear_ip_filter')) ?></a>
            <?php endif; ?>
        </header>
        <div class="admin-security-log-head" aria-hidden="true">
            <span><?= e(t('security.ip')) ?></span>
            <span><?= e(t('security.request')) ?></span>
            <span><?= e(t('security.result')) ?></span>
            <span><?= e(t('security.date')) ?></span>
        </div>
        <div class="admin-security-log-list">
            <?php if ($securityLogs === []): ?>
                <div class="admin-security-empty"><p><?= e(t('security.no_access_logs')) ?></p></div>
            <?php else: ?>
                <?php foreach ($securityLogs as $securityLog): ?>
                    <?php
                    $securityRisk = (int) ($securityLog['risk_score'] ?? 0);
                    $securityStatus = (int) ($securityLog['status_code'] ?? 0);
                    $securityEvent = (string) ($securityLog['event_type'] ?? 'request');
                    $securityTone = $securityRisk >= 70 || $securityStatus === 429
                        ? 'danger'
                        : ($securityStatus >= 400 ? 'warning' : 'normal');
                    $securityAgent = trim((string) ($securityLog['user_agent'] ?? ''));
                    ?>
                    <article class="admin-security-log-row is-<?= e($securityTone) ?>">
                        <span class="admin-security-log-ip">
                            <a href="/?page=admin&amp;section=security&amp;security_ip=<?= rawurlencode((string) ($securityLog['ip_address'] ?? '')) ?>" data-spa-link><?= e((string) ($securityLog['ip_address'] ?? 'unknown')) ?></a>
                            <?php if ((string) ($securityLog['network_ip'] ?? '') !== (string) ($securityLog['ip_address'] ?? '')): ?><small><?= e(t('security.via_proxy', ['ip' => (string) ($securityLog['network_ip'] ?? '')])) ?></small><?php endif; ?>
                            <?php if (trim((string) ($securityLog['user_name'] ?? '')) !== ''): ?><small><?= e((string) $securityLog['user_name']) ?></small><?php endif; ?>
                        </span>
                        <span class="admin-security-log-request" title="<?= e($securityAgent) ?>">
                            <b><?= e((string) ($securityLog['method'] ?? 'GET')) ?></b>
                            <code><?= e((string) ($securityLog['path'] ?? '/')) ?></code>
                            <?php if (trim((string) ($securityLog['host'] ?? '')) !== ''): ?><small><?= e((string) $securityLog['host']) ?></small><?php endif; ?>
                        </span>
                        <span class="admin-security-log-result">
                            <b><?= $securityStatus > 0 ? $securityStatus : '…' ?></b>
                            <small><?= e(t('security.event_' . $securityEvent)) ?></small>
                        </span>
                        <time datetime="<?= e((string) ($securityLog['created_at'] ?? '')) ?>"><?= e($securityFormatDate($securityLog['created_at'] ?? '')) ?></time>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
</article>
