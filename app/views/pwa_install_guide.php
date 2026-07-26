<?php

declare(strict_types=1);

$pwaGuideIosSteps = [
    'pwa_guide.ios_step_1',
    'pwa_guide.ios_step_2',
    'pwa_guide.ios_step_3',
    'pwa_guide.ios_step_4',
    'pwa_guide.ios_step_5',
    'pwa_guide.ios_step_6',
    'pwa_guide.ios_step_7',
];
$pwaGuideAndroidBrowsers = [
    'chrome' => ['title' => 'pwa_guide.android_chrome_title', 'hint' => 'pwa_guide.android_chrome_hint', 'steps' => ['pwa_guide.android_chrome_step_1', 'pwa_guide.android_chrome_step_2', 'pwa_guide.android_chrome_step_3']],
    'firefox' => ['title' => 'pwa_guide.android_firefox_title', 'hint' => 'pwa_guide.android_firefox_hint', 'steps' => ['pwa_guide.android_firefox_step_1', 'pwa_guide.android_firefox_step_2', 'pwa_guide.android_firefox_step_3']],
    'samsung' => ['title' => 'pwa_guide.android_samsung_title', 'hint' => 'pwa_guide.android_samsung_hint', 'steps' => ['pwa_guide.android_samsung_step_1', 'pwa_guide.android_samsung_step_2', 'pwa_guide.android_samsung_step_3']],
    'edge' => ['title' => 'pwa_guide.android_edge_title', 'hint' => 'pwa_guide.android_edge_hint', 'steps' => ['pwa_guide.android_edge_step_1', 'pwa_guide.android_edge_step_2', 'pwa_guide.android_edge_step_3']],
    'other' => ['title' => 'pwa_guide.android_other_title', 'hint' => 'pwa_guide.android_other_hint', 'steps' => ['pwa_guide.android_other_step_1', 'pwa_guide.android_other_step_2', 'pwa_guide.android_other_step_3']],
];
?>
<section class="screen stack-lg pwa-guide-screen" data-pwa-install-guide>
    <div class="hero-panel app-page-hero">
        <div class="hero-copy hero-copy-page-title">
            <p class="eyebrow"><?= e(t('pwa_guide.eyebrow')) ?></p>
            <h1><?= e(t('pwa_guide.title')) ?></h1>
            <p class="muted"><?= e(t('pwa_guide.intro')) ?></p>
        </div>
    </div>

    <nav class="pwa-guide-tabs" role="tablist" aria-label="<?= e(t('pwa_guide.switch_browser')) ?>">
        <button type="button" class="pwa-guide-tab" data-pwa-guide-tab="ios" role="tab" aria-selected="false">
            <span aria-hidden="true"><?= activity_icon_svg('shield') ?></span><?= e(t('pwa_guide.tab_ios')) ?>
        </button>
        <button type="button" class="pwa-guide-tab" data-pwa-guide-tab="android" role="tab" aria-selected="false">
            <span aria-hidden="true"><?= activity_icon_svg('download') ?></span><?= e(t('pwa_guide.tab_android')) ?>
        </button>
        <button type="button" class="pwa-guide-tab" data-pwa-guide-tab="desktop" role="tab" aria-selected="false">
            <span aria-hidden="true"><?= activity_icon_svg('link') ?></span><?= e(t('pwa_guide.tab_desktop')) ?>
        </button>
    </nav>

    <article class="panel compact-panel glass-panel pwa-guide-panel" data-pwa-guide-panel="ios" role="tabpanel" hidden>
        <div class="pwa-guide-panel-head">
            <span class="pwa-guide-panel-icon" aria-hidden="true"><?= activity_icon_svg('shield') ?></span>
            <div>
                <p class="eyebrow"><?= e(t('pwa_guide.tab_ios')) ?></p>
                <h2><?= e(t('pwa_guide.ios_safari_title')) ?></h2>
                <p class="muted"><?= e(t('pwa_guide.ios_safari_hint')) ?></p>
            </div>
        </div>
        <ol class="pwa-guide-steps">
            <?php foreach ($pwaGuideIosSteps as $stepIndex => $stepKey): ?>
                <li><b><?= $stepIndex + 1 ?></b><span><?= e(t($stepKey)) ?></span></li>
            <?php endforeach; ?>
        </ol>

        <div class="pwa-guide-callout" data-pwa-guide-ios-warning hidden>
            <span aria-hidden="true"><?= activity_icon_svg('bell') ?></span>
            <div>
                <strong><?= e(t('pwa_guide.ios_other_title')) ?></strong>
                <p><?= e(t('pwa_guide.ios_other_body')) ?></p>
            </div>
        </div>
    </article>

    <article class="panel compact-panel glass-panel pwa-guide-panel" data-pwa-guide-panel="android" role="tabpanel" hidden>
        <div class="pwa-guide-panel-head">
            <span class="pwa-guide-panel-icon" aria-hidden="true"><?= activity_icon_svg('download') ?></span>
            <div>
                <p class="eyebrow"><?= e(t('pwa_guide.tab_android')) ?></p>
                <h2><?= e(t('pwa_guide.android_generic_title')) ?></h2>
                <p class="muted"><?= e(t('pwa_guide.android_generic_hint')) ?></p>
            </div>
        </div>

        <?php foreach ($pwaGuideAndroidBrowsers as $browserKey => $browser): ?>
            <section class="pwa-guide-browser-section" data-pwa-guide-android-browser="<?= e($browserKey) ?>" hidden>
                <div class="pwa-guide-panel-subhead">
                    <strong><?= e(t($browser['title'])) ?></strong>
                    <small><?= e(t($browser['hint'])) ?></small>
                </div>
                <ol class="pwa-guide-steps">
                    <?php foreach ($browser['steps'] as $stepIndex => $stepKey): ?>
                        <li><b><?= $stepIndex + 1 ?></b><span><?= e(t($stepKey)) ?></span></li>
                    <?php endforeach; ?>
                </ol>
            </section>
        <?php endforeach; ?>

        <div class="pwa-guide-browser-switch" data-pwa-guide-android-switch>
            <p class="muted small"><?= e(t('pwa_guide.switch_browser')) ?></p>
            <div class="pwa-guide-browser-switch-list">
                <?php foreach ($pwaGuideAndroidBrowsers as $browserKey => $browser): ?>
                    <button type="button" class="pwa-guide-browser-pill" data-pwa-guide-android-select="<?= e($browserKey) ?>"><?= e(t($browser['title'])) ?></button>
                <?php endforeach; ?>
            </div>
        </div>
    </article>

    <article class="panel compact-panel glass-panel pwa-guide-panel" data-pwa-guide-panel="desktop" role="tabpanel" hidden>
        <div class="pwa-guide-panel-head">
            <span class="pwa-guide-panel-icon" aria-hidden="true"><?= activity_icon_svg('link') ?></span>
            <div>
                <p class="eyebrow"><?= e(t('pwa_guide.tab_desktop')) ?></p>
                <h2><?= e(t('pwa_guide.desktop_title')) ?></h2>
            </div>
        </div>
        <p><?= e(t('pwa_guide.desktop_body')) ?></p>
    </article>
</section>
