<?php

/**
 * AI behaviour pane: Autonomous mode.
 *
 * Uses `$context` array provided by `ai-setup.php` to display the generated system
 * prompt and selected model. Output is read-only and fully escaped for HTML
 * contexts; the parent template handles form state and visibility toggling.
 */

if (!defined('ABSPATH')) {
    exit;
}

$description = isset($context['description']) ? $context['description'] : '';
$systemPrompt = isset($context['system_prompt']) ? $context['system_prompt'] : '';
$systemPromptFieldId = isset($context['system_prompt_field_id']) ? $context['system_prompt_field_id'] : '';
$aiModel = isset($context['ai_model']) ? $context['ai_model'] : '';
$aiModelFieldId = isset($context['ai_model_field_id']) ? $context['ai_model_field_id'] : '';
$aiModelDisplay = isset($context['ai_model_display']) ? $context['ai_model_display'] : $aiModel;
?>
<p class="description exmoau-settings__behaviour-pane-description"><?php echo esc_html($description); ?></p>
<div class="exmoau-settings__field-group">
    <label class="exmoau-settings__field-label" for="<?php echo esc_attr($systemPromptFieldId); ?>"><?php esc_html_e('System Prompt', 'exmoment-author'); ?></label>
    <textarea
        id="<?php echo esc_attr($systemPromptFieldId); ?>"
        class="large-text code exmoau-settings__field-control"
        rows="6"
        readonly
        disabled
    ><?php echo esc_textarea($systemPrompt); ?></textarea>
</div>
<div class="exmoau-settings__field-group">
    <label class="exmoau-settings__field-label" for="<?php echo esc_attr($aiModelFieldId); ?>"><?php esc_html_e('AI Model', 'exmoment-author'); ?></label>
    <input
        id="<?php echo esc_attr($aiModelFieldId); ?>"
        type="text"
        class="regular-text exmoau-settings__field-control"
        value="<?php echo esc_attr($aiModelDisplay); ?>"
        readonly
        disabled
    />
</div>
