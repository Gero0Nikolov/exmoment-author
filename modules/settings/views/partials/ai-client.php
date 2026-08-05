<?php

/**
 * Provider-aware WordPress AI Client settings.
 */

use ExMomentAuthor\Modules\Gpt\GptController;
use ExMomentAuthor\Modules\Settings\SettingsController;

if (!defined('ABSPATH')) {
    exit;
}

$status = SettingsController::getAiConnectionStatus('text');
$providers = isset($status['providers']) && is_array($status['providers']) ? $status['providers'] : array();
$selectedProvider = SettingsController::getAiProvider();
$connectorsUrl = admin_url('options-connectors.php');
$statusLabels = array(
    'connected'               => __('Connected', 'exmoment-author'),
    'client_unavailable'      => __('WordPress AI Client unavailable', 'exmoment-author'),
    'provider_unavailable'    => __('No compatible provider available', 'exmoment-author'),
    'provider_not_configured' => __('No compatible provider configured', 'exmoment-author'),
    'invalid_model'           => __('Selected model unavailable', 'exmoment-author'),
    'unsupported_capability'  => __('Provider does not support text generation', 'exmoment-author'),
);
$connectionStatus = isset($status['connection_status']) ? (string) $status['connection_status'] : 'client_unavailable';
$connectionLabel = isset($statusLabels[$connectionStatus]) ? $statusLabels[$connectionStatus] : __('Disconnected', 'exmoment-author');
$suggestedProviders = array(
    array(
        'name' => __('OpenAI', 'exmoment-author'),
        'url'  => 'https://wordpress.org/plugins/ai-provider-for-openai/',
    ),
    array(
        'name' => __('Anthropic', 'exmoment-author'),
        'url'  => 'https://wordpress.org/plugins/ai-provider-for-anthropic/',
    ),
    array(
        'name' => __('Google', 'exmoment-author'),
        'url'  => 'https://wordpress.org/plugins/ai-provider-for-google/',
    ),
);
?>
<tr>
    <th scope="row"><?php esc_html_e('Connection status', 'exmoment-author'); ?></th>
    <td>
        <strong><?php echo esc_html($connectionLabel); ?></strong>
        <p class="description">
            <?php esc_html_e('Provider credentials are managed by WordPress, not ExMoment Author.', 'exmoment-author'); ?>
            <a href="<?php echo esc_url($connectorsUrl); ?>"><?php esc_html_e('Open WordPress Connectors', 'exmoment-author'); ?></a>
        </p>
    </td>
</tr>
<tr>
    <th scope="row"><label for="exmoau_ai_image_generation_enabled"><?php esc_html_e('AI featured images', 'exmoment-author'); ?></label></th>
    <td>
        <label>
            <input type="checkbox" name="<?php echo esc_attr(SettingsController::getOptionFieldName('ai_image_generation_enabled')); ?>" id="exmoau_ai_image_generation_enabled" value="1" <?php checked(SettingsController::isAiImageGenerationEnabled()); ?> />
            <?php esc_html_e('Generate a featured image after article creation.', 'exmoment-author'); ?>
        </label>
    </td>
</tr>
<tr>
    <th scope="row"><label for="exmoau_ai_image_style_prompt"><?php esc_html_e('Image style prompt', 'exmoment-author'); ?></label></th>
    <td>
        <textarea name="<?php echo esc_attr(SettingsController::getOptionFieldName('ai_image_style_prompt')); ?>" id="exmoau_ai_image_style_prompt" rows="4" class="large-text"><?php echo esc_textarea(SettingsController::getAiImageStylePrompt()); ?></textarea>
        <p class="description"><?php esc_html_e('Prepended to the generated article excerpt used for the image prompt.', 'exmoment-author'); ?></p>
    </td>
</tr>
<tr>
    <th scope="row"><label for="exmoau_ai_image_dimensions"><?php esc_html_e('Image dimensions', 'exmoment-author'); ?></label></th>
    <td>
        <select name="<?php echo esc_attr(SettingsController::getOptionFieldName('ai_image_dimensions')); ?>" id="exmoau_ai_image_dimensions">
            <?php foreach (SettingsController::getAllowedAiImageDimensions() as $dimensions) : ?>
                <option value="<?php echo esc_attr($dimensions); ?>" <?php selected(SettingsController::getAiImageDimensions(), $dimensions); ?>><?php echo esc_html($dimensions); ?></option>
            <?php endforeach; ?>
        </select>
    </td>
</tr>
<tr>
    <th scope="row"><label for="exmoau_ai_provider"><?php esc_html_e('AI provider', 'exmoment-author'); ?></label></th>
    <td>
        <select name="<?php echo esc_attr(SettingsController::getOptionFieldName('ai_provider')); ?>" id="exmoau_ai_provider">
            <option value="" <?php selected($selectedProvider, ''); ?>><?php esc_html_e('Automatic selection', 'exmoment-author'); ?></option>
            <?php foreach ($providers as $provider) : ?>
                <option value="<?php echo esc_attr($provider['id']); ?>" <?php selected($selectedProvider, $provider['id']); ?>>
                    <?php echo esc_html($provider['name'] . (empty($provider['configured']) ? ' — ' . __('not configured', 'exmoment-author') : '')); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description">
            <?php
            printf(
                esc_html__('Selected provider: %1$s. Selected model: %2$s.', 'exmoment-author'),
                esc_html($status['selected_provider'] !== '' ? $status['selected_provider'] : __('Automatic', 'exmoment-author')),
                esc_html($status['selected_model'] !== '' ? $status['selected_model'] : __('Automatic', 'exmoment-author'))
            );
            ?>
        </p>
    </td>
</tr>
<?php if ($providers === array()) : ?>
<tr>
    <th scope="row"><?php esc_html_e('Suggested providers', 'exmoment-author'); ?></th>
    <td>
        <p><?php esc_html_e('Install and configure an official WordPress provider adapter:', 'exmoment-author'); ?></p>
        <ul>
            <?php foreach ($suggestedProviders as $suggestedProvider) : ?>
                <li>
                    <a href="<?php echo esc_url($suggestedProvider['url']); ?>" target="_blank" rel="noopener noreferrer">
                        <?php echo esc_html($suggestedProvider['name']); ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </td>
</tr>
<?php endif; ?>
<tr>
    <th scope="row"><label for="exmoau_ai_token_budget"><?php esc_html_e('Output token budget', 'exmoment-author'); ?></label></th>
    <td>
        <select name="<?php echo esc_attr(SettingsController::getOptionFieldName('ai_token_budget')); ?>" id="exmoau_ai_token_budget">
            <?php foreach (GptController::getWeightsMap() as $weightKey => $maxTokens) : ?>
                <option value="<?php echo esc_attr((string) $weightKey); ?>" <?php selected(SettingsController::getAiTokenBudgetKey(), (string) $weightKey); ?>>
                    <?php echo esc_html(sprintf('%s (%d tokens)', (string) $weightKey, (int) $maxTokens)); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </td>
</tr>
<tr>
    <th scope="row"><label for="exmoau_gpt_debug_mode"><?php esc_html_e('AI debug mode', 'exmoment-author'); ?></label></th>
    <td>
        <label>
            <input type="checkbox" name="<?php echo esc_attr(SettingsController::getOptionFieldName('gpt_debug_mode')); ?>" id="exmoau_gpt_debug_mode" value="1" <?php checked(SettingsController::isGptDebugModeEnabled()); ?> />
            <?php esc_html_e('Return deterministic test content without contacting a provider.', 'exmoment-author'); ?>
        </label>
    </td>
</tr>
<?php
$imageModels = SettingsController::getAiImageModelRegistry();
$imageModel = SettingsController::getAiImageModel();
?>
<tr>
    <th scope="row"><label for="exmoau_ai_image_model"><?php esc_html_e('Image model', 'exmoment-author'); ?></label></th>
    <td>
        <select name="<?php echo esc_attr(SettingsController::getOptionFieldName('ai_image_model')); ?>" id="exmoau_ai_image_model">
            <option value=""><?php esc_html_e('Automatic selection', 'exmoment-author'); ?></option>
            <?php foreach ($imageModels as $modelId => $model) : ?>
                <option value="<?php echo esc_attr($modelId); ?>" <?php selected($imageModel, $modelId); ?>><?php echo esc_html($model['label']); ?></option>
            <?php endforeach; ?>
        </select>
    </td>
</tr>
