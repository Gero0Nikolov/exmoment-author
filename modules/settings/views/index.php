<?php

/**
 * Settings page view for ExMoment Author options.
 *
 * Expects `$tabs` as an associative array of tab configurations with `label` and
 * `partial` keys and `$activeTab` for the selected tab slug. The template outputs
 * tab navigation and includes per-tab partials; each partial is responsible for
 * escaping its own fields. WordPress helper functions handle nonce fields and
 * settings sections.
 */

use ExMomentAuthor\Modules\Settings\SettingsController;

if (!defined('ABSPATH')) {
    exit;
}

$heading = esc_html__('ExMoment Author — Settings', 'exmoment-author');
$formAction = esc_url(admin_url('options.php'));
$tabs = (isset($tabs) && is_array($tabs)) ? $tabs : [];
$activeTab = (isset($activeTab) && is_string($activeTab)) ? $activeTab : 'openai';

if (!array_key_exists($activeTab, $tabs) && !empty($tabs)) {
    $activeTab = array_key_first($tabs);
}

$tablistId = 'exmoau-settings-tabs';
$baseTabUrl = admin_url(sprintf('options-general.php?page=%s', SettingsController::PAGE_SLUG));
$baseTabUrl = remove_query_arg('tab', $baseTabUrl);

echo '<div class="wrap exmoau-settings exmoau-settings--page">';
echo '<h1>' . esc_html($heading) . '</h1>';

printf(
    '<nav class="nav-tab-wrapper exmoau-settings__tabs" role="tablist" aria-labelledby="%s">',
    esc_attr($tablistId)
);

printf(
    '<span id="%1$s" class="screen-reader-text">%2$s</span>',
    esc_attr($tablistId),
    esc_html__('Settings tabs', 'exmoment-author')
);

foreach ($tabs as $tabKey => $tabConfig) {
    if (!is_array($tabConfig)) {
        continue;
    }

    $tabId = sprintf('exmoau-tab-%s', $tabKey);
    $panelId = sprintf('exmoau-tabpanel-%s', $tabKey);
    $isActive = ($tabKey === $activeTab);
    $tabClasses = 'nav-tab exmoau-settings__tab';

    if ($isActive) {
        $tabClasses .= ' nav-tab-active';
    }

    $tabUrl = add_query_arg('tab', $tabKey, $baseTabUrl);
    $tabLabel = esc_html($tabConfig['label'] ?? ucfirst($tabKey));

    $tabClassesAttr = esc_attr($tabClasses);
    $tabIdAttr = esc_attr($tabId);
    $tabUrlAttr = esc_url($tabUrl);
    $panelIdAttr = esc_attr($panelId);
    $ariaSelected = ($isActive ? 'true' : 'false');
    $tabIndex = ($isActive ? '0' : '-1');

    printf(
        '<a class="%1$s" role="tab" id="%2$s" href="%3$s" aria-controls="%4$s" aria-selected="%5$s" tabindex="%6$s">%7$s</a>',
        esc_attr($tabClassesAttr),
        esc_attr($tabIdAttr),
        esc_url($tabUrlAttr),
        esc_attr($panelIdAttr),
        esc_attr($ariaSelected),
        esc_attr($tabIndex),
        esc_html($tabLabel)
    );
}

echo '</nav>';
echo '<form method="post" action="' . esc_url($formAction) . '">';

settings_fields(SettingsController::SETTINGS_GROUP);

do_settings_sections(SettingsController::PAGE_SLUG);

echo '<div class="exmoau-settings__panels">';

foreach ($tabs as $tabKey => $tabConfig) {
    if (!is_array($tabConfig)) {
        continue;
    }

    $panelId = sprintf('exmoau-tabpanel-%s', $tabKey);
    $tabId = sprintf('exmoau-tab-%s', $tabKey);
    $panelClasses = 'exmoau-settings__panel';
    $isActive = ($tabKey === $activeTab);

    if (!$isActive) {
        $panelClasses .= ' exmoau-is-hidden';
    }

    $panelLabelledBy = esc_attr($tabId);
    $panelAriaHidden = ($isActive ? 'false' : 'true');
    $partialPath = $tabConfig['partial'] ?? '';

    $panelIdAttr = esc_attr($panelId);
    $panelClassesAttr = esc_attr($panelClasses);
    $panelLabelAttr = esc_attr($panelLabelledBy);
    $panelHiddenAttr = esc_attr($panelAriaHidden);

    printf(
        '<div id="%1$s" class="%2$s" role="tabpanel" aria-labelledby="%3$s" aria-hidden="%4$s">',
        esc_attr($panelIdAttr),
        esc_attr($panelClassesAttr),
        esc_attr($panelLabelAttr),
        esc_attr($panelHiddenAttr)
    );

    echo '<table class="form-table" role="presentation">';
    echo '<tbody>';

    if (is_string($partialPath) && file_exists($partialPath)) {
        include $partialPath;
    }

    echo '</tbody>';
    echo '</table>';
    echo '</div>';
}

echo '</div>';

submit_button(esc_html__('Update', 'exmoment-author'));

echo '</form>';
echo '</div>';
