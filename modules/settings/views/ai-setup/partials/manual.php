<?php

/**
 * AI behaviour pane: Manual mode.
 *
 * Consumes `$context` from `ai-setup.php` including raw system prompt text and
 * selectable model IDs. The parent template controls visibility and form
 * submission. Dynamic values are escaped for HTML attributes and text areas;
 * model labels are sanitized before display.
 */

if (!defined('ABSPATH')) {
    exit;
}

$description = isset($context['description']) ? $context['description'] : '';
$userSystemPrompt = isset($context['user_system_prompt']) ? $context['user_system_prompt'] : '';
$userFieldName = isset($context['user_system_prompt_field_name']) ? $context['user_system_prompt_field_name'] : '';
$userFieldId = isset($context['user_system_prompt_field_id']) ? $context['user_system_prompt_field_id'] : '';
$aiModel = isset($context['ai_model']) ? $context['ai_model'] : '';
$aiModelFieldName = isset($context['ai_model_field_name']) ? $context['ai_model_field_name'] : '';
$aiModelFieldId = isset($context['ai_model_field_id']) ? $context['ai_model_field_id'] : '';
$aiModelOptions = (isset($context['ai_model_options']) && is_array($context['ai_model_options'])) ? $context['ai_model_options'] : [];

if ($aiModel === '' || ($aiModelOptions !== [] && !array_key_exists($aiModel, $aiModelOptions))) {
    $firstOption = array_key_first($aiModelOptions);
    $aiModel = is_string($firstOption) ? $firstOption : '';
}
?>
<p class="description exmoau-settings__behaviour-pane-description"><?php echo esc_html($description); ?></p>
<div class="exmoau-settings__field-group">
    <label class="exmoau-settings__field-label" for="<?php echo esc_attr($userFieldId); ?>"><?php esc_html_e('User System Prompt', 'exmoment-author'); ?></label>
    <textarea
        name="<?php echo esc_attr($userFieldName); ?>"
        id="<?php echo esc_attr($userFieldId); ?>"
        class="large-text code exmoau-settings__field-control"
        rows="6"
    ><?php echo esc_textarea($userSystemPrompt); ?></textarea>
    <p class="description"><?php esc_html_e('Provide the full instructions ExMoment Author will follow without modification.', 'exmoment-author'); ?></p>
</div>
<div class="exmoau-settings__field-group">
    <label class="exmoau-settings__field-label" for="<?php echo esc_attr($aiModelFieldId); ?>"><?php esc_html_e('AI Model', 'exmoment-author'); ?></label>
    <select
        name="<?php echo esc_attr($aiModelFieldName); ?>"
        id="<?php echo esc_attr($aiModelFieldId); ?>"
        class="exmoau-settings__field-control"
    >
        <?php foreach ($aiModelOptions as $modelValue => $modelLabel) :
            $selected = selected($aiModel, $modelValue, false);
        ?>
        <option value="<?php echo esc_attr($modelValue); ?>" <?php echo esc_html($selected); ?>><?php echo esc_html($modelLabel); ?></option>
        <?php endforeach; ?>
    </select>
    <p class="description"><?php esc_html_e('Choose the model that should execute prompts exactly as provided.', 'exmoment-author'); ?></p>
</div>
