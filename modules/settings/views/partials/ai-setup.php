<?php

/**
 * AI behaviour configuration partial.
 *
 * Receives context arrays for each behaviour mode (`autonomous`, `augmented`,
 * `manual`) and renders the selector plus per-mode partials. `$behaviourOptions`
 * carries partial paths and sanitized context data; each included partial is
 * responsible for escaping its own output. Translation and attribute escaping are
 * applied to all inline strings.
 */

use ExMomentAuthor\Modules\Settings\SettingsController;

if (!defined('ABSPATH')) {
    exit;
}

$behaviourOptionKey = 'ai_behaviour_mode';
$currentValue = SettingsController::getOption($behaviourOptionKey, 'autonomous');
$fieldName = SettingsController::getOptionFieldName($behaviourOptionKey);
$fieldId = 'exmoau_ai_behaviour_mode';
$label = esc_html__('AI Behaviour Configuration', 'exmoment-author');
$generalDescription = esc_html__(
    'Choose how ExMoment Author coordinates AI support when managing writing workflows.',
    'exmoment-author'
);
$descriptionId = 'exmoau_ai_behaviour_mode_description';
$authorContextFieldName = SettingsController::getOptionFieldName('include_author_name_in_ai_context');
$authorContextEnabled = SettingsController::shouldIncludeAuthorNameInAiContext();

$defaultAiModel = SettingsController::getDefaultAiModel();
$systemPromptTemplate = SettingsController::getAutonomousSystemPrompt();

$augmentedOptimizedPrompt = SettingsController::getAugmentedOptimizedPrompt();
$augmentedUserSystemPrompt = SettingsController::getOption('augmented_user_system_prompt');
$augmentedAiModel = SettingsController::getOption('augmented_ai_model', $defaultAiModel);
$manualUserSystemPrompt = SettingsController::getOption('manual_user_system_prompt');
$manualAiModel = SettingsController::getOption('manual_ai_model', $defaultAiModel);

$storedModelIds = array_filter(
    [
        $defaultAiModel,
        (is_string($augmentedAiModel) ? $augmentedAiModel : ''),
        (is_string($manualAiModel) ? $manualAiModel : ''),
    ],
    /**
     * Validate stored model identifiers to ensure they are non-empty strings.
     *
     * @param mixed $value Candidate model identifier from persisted options.
     * @return bool True when the value is a non-empty string.
     */
    static function ($value) {
        return is_string($value) && $value !== '';
    }
);

$availableAiModels = SettingsController::getAvailableAiModels($storedModelIds);
$aiModelOptions = [];
$aiModelNamesById = [];

foreach ($availableAiModels as $model) {
    if (!is_array($model)) {
        continue;
    }

    $modelId = isset($model['id']) ? (string) $model['id'] : '';
    $modelName = isset($model['name']) ? (string) $model['name'] : '';

    $modelId = strtolower(trim($modelId));

    if ($modelId === '') {
        continue;
    }

    $modelName = sanitize_text_field($modelName);

    if ($modelName === '') {
        $modelName = $modelId;
    }

    $aiModelNamesById[$modelId] = $modelName;
    $aiModelOptions[$modelId] = $modelName;
}

if ($aiModelOptions === []) {
    $aiModelOptions[''] = esc_html__('Automatic selection', 'exmoment-author');
    $aiModelNamesById[''] = esc_html__('Automatic selection', 'exmoment-author');
}

$autonomousModelName = isset($aiModelNamesById[$defaultAiModel]) ? $aiModelNamesById[$defaultAiModel] : '';
$autonomousModelDisplay = $defaultAiModel;

if ($autonomousModelName !== '') {
    $autonomousModelDisplay = sprintf('%s (%s)', $autonomousModelName, $defaultAiModel);
}

$aiSetupPartialsDirectory = dirname(__DIR__) . '/ai-setup/partials/';

$behaviourOptions = [
    'autonomous' => [
        'label'        => esc_html__('Autonomous', 'exmoment-author'),
        'summary'      => esc_html__('Autonomous: ExMoment Author acts independently, queuing and completing tasks without manual review.', 'exmoment-author'),
        'heading'      => esc_html__('Autonomous Mode', 'exmoment-author'),
        'partial_path' => $aiSetupPartialsDirectory . 'autonomous.php',
        'context'      => [
            'description'            => esc_html__('This method defines an AI-optimized system prompt and automatically selects a compatible model from the configured provider.', 'exmoment-author'),
            'system_prompt'          => $systemPromptTemplate,
            'system_prompt_field_id' => 'exmoau_autonomous_system_prompt',
            'ai_model'               => $defaultAiModel,
            'ai_model_field_id'      => 'exmoau_autonomous_ai_model',
            'ai_model_display'       => $autonomousModelDisplay,
        ],
    ],
    'augmented' => [
        'label'        => esc_html__('Augmented', 'exmoment-author'),
        'summary'      => esc_html__('Augmented: ExMoment Author drafts suggestions and requires human confirmation before publishing.', 'exmoment-author'),
        'heading'      => esc_html__('Augmented Mode', 'exmoment-author'),
        'partial_path' => $aiSetupPartialsDirectory . 'augmented.php',
        'context'      => [
            'description'                    => esc_html__('This method allows the user to define the default system prompt. On save, ExMoment Author optimizes the input with the selected compatible model and stores the enhanced version for reuse.', 'exmoment-author'),
            'system_prompt'                  => ($augmentedOptimizedPrompt !== '' ? $augmentedOptimizedPrompt : ''),
            'system_prompt_field_id'         => 'exmoau_augmented_system_prompt',
            'user_system_prompt'             => $augmentedUserSystemPrompt,
            'user_system_prompt_field_name'  => SettingsController::getOptionFieldName('augmented_user_system_prompt'),
            'user_system_prompt_field_id'    => 'exmoau_augmented_user_system_prompt',
            'ai_model'                       => ($augmentedAiModel !== '' ? $augmentedAiModel : $defaultAiModel),
            'ai_model_field_name'            => SettingsController::getOptionFieldName('augmented_ai_model'),
            'ai_model_field_id'              => 'exmoau_augmented_ai_model',
            'ai_model_options'               => $aiModelOptions,
        ],
    ],
    'manual' => [
        'label'        => esc_html__('Manual', 'exmoment-author'),
        'summary'      => esc_html__('Manual: ExMoment Author surfaces tools while leaving orchestration entirely to editors.', 'exmoment-author'),
        'heading'      => esc_html__('Manual Mode', 'exmoment-author'),
        'partial_path' => $aiSetupPartialsDirectory . 'manual.php',
        'context'      => [
            'description'                    => esc_html__('This method allows the user to define the system prompt and update the model. ExMoment Author will use it exactly as provided with no optimization.', 'exmoment-author'),
            'user_system_prompt'             => $manualUserSystemPrompt,
            'user_system_prompt_field_name'  => SettingsController::getOptionFieldName('manual_user_system_prompt'),
            'user_system_prompt_field_id'    => 'exmoau_manual_user_system_prompt',
            'ai_model'                       => ($manualAiModel !== '' ? $manualAiModel : $defaultAiModel),
            'ai_model_field_name'            => SettingsController::getOptionFieldName('manual_ai_model'),
            'ai_model_field_id'              => 'exmoau_manual_ai_model',
            'ai_model_options'               => $aiModelOptions,
        ],
    ],
];

$selectAriaControls = implode(
    ' ',
    array_map(
        /**
         * Build the aria-controls identifier for each behaviour option key.
         *
         * @param string $key Behaviour option key, such as "autonomous".
         * @return string DOM id for the related setup pane.
         */
        static function ($key) {
            return sprintf('ai-setup-pane-%s', $key);
        },
        array_keys($behaviourOptions)
    )
);
?>
<tr>
    <th scope="row">
        <label for="exmoau_include_author_name_in_ai_context">
            <?php esc_html_e('Include author name in AI context', 'exmoment-author'); ?>
        </label>
    </th>
    <td>
        <input type="hidden" name="<?php echo esc_attr($authorContextFieldName); ?>" value="0" />
        <label for="exmoau_include_author_name_in_ai_context">
            <input
                type="checkbox"
                id="exmoau_include_author_name_in_ai_context"
                name="<?php echo esc_attr($authorContextFieldName); ?>"
                value="1"
                <?php checked($authorContextEnabled); ?>
            />
            <?php esc_html_e('Send the selected WordPress author’s display name to the AI when generating article content and featured images.', 'exmoment-author'); ?>
        </label>
        <p class="description">
            <?php esc_html_e('Disabled by default. Only the public display name is sent. For featured images, it guides the gender presentation of an article-relevant person without requesting a portrait, likeness, byline, or visible text.', 'exmoment-author'); ?>
        </p>
    </td>
</tr>
<tr>
    <th scope="row">
        <label for="<?php echo esc_attr($fieldId); ?>"><?php echo esc_html($label); ?></label>
    </th>
    <td>
        <select
            name="<?php echo esc_attr($fieldName); ?>"
            id="<?php echo esc_attr($fieldId); ?>"
            aria-describedby="<?php echo esc_attr($descriptionId); ?>"
            aria-controls="<?php echo esc_attr($selectAriaControls); ?>"
        >
            <?php foreach ($behaviourOptions as $key => $option) :
                $selected = selected($currentValue, $key, false);
            ?>
            <option value="<?php echo esc_attr($key); ?>" <?php echo $selected; ?>><?php echo esc_html($option['label']); ?></option>
            <?php endforeach; ?>
        </select>
        <p class="description" id="<?php echo esc_attr($descriptionId); ?>"><?php echo esc_html($generalDescription); ?></p>
        <div class="exmoau-settings__behaviour-panes">
            <?php foreach ($behaviourOptions as $key => $option) :
                $paneId = sprintf('ai-setup-pane-%s', $key);
                $headingId = sprintf('%s-heading', $paneId);
                $context = $option['context'] ?? [];
                $partialPath = $option['partial_path'] ?? '';
            ?>
            <div
                id="<?php echo esc_attr($paneId); ?>"
                class="exmoau-settings__behaviour-pane exmoau-is-hidden"
                data-behaviour="<?php echo esc_attr($key); ?>"
                role="region"
                aria-labelledby="<?php echo esc_attr($headingId); ?>"
                aria-hidden="true"
            >
                <h3 id="<?php echo esc_attr($headingId); ?>" class="exmoau-settings__behaviour-pane-title"><?php echo esc_html($option['heading']); ?></h3>
                <?php if (is_string($partialPath) && file_exists($partialPath)) {
                    include $partialPath;
                } ?>
            </div>
            <?php endforeach; ?>
        </div>
    </td>
</tr>
