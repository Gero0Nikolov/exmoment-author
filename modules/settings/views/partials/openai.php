<?php

/**
 * OpenAI credentials and debug mode settings partial.
 *
 * Expects option values from `SettingsController` to be loaded into `$optionName`
 * and checkbox state for GPT debug mode. The parent form handles nonce and
 * capability checks. All interpolated values are escaped for HTML attributes and
 * descriptions rely on translated strings.
 */

use ExMomentAuthor\Modules\Gpt\GptController;
use ExMomentAuthor\Modules\Settings\SettingsController;

if (!defined('ABSPATH')) {
    exit;
}

$optionName = SettingsController::getOption('openai_api_key');
$fieldName = SettingsController::getOptionFieldName('openai_api_key');
$label = esc_html__('Open AI: API Key', 'exmoment-author');
$description = esc_html__(
    'Enter the OpenAI API key associated with your account. This value is stored securely within WordPress.',
    'exmoment-author'
);
$inputId = 'exmoau_openai_api_key';
$fieldNameAttr = esc_attr($fieldName);
$inputIdAttr = esc_attr($inputId);
$inputValue = esc_attr($optionName);

echo '
<tr>
    <th scope="row">
        <label for="' . esc_attr($inputIdAttr) . '">' . esc_html($label) . '</label>
    </th>
    <td>
        <input
            name="' . esc_attr($fieldNameAttr) . '"
            type="password"
            id="' . esc_attr($inputIdAttr) . '"
            value="' . esc_attr($inputValue) . '"
            class="regular-text"
            autocomplete="off"
        />
        <p class="description">' . esc_html($description) . '</p>
    </td>
</tr>
';

$debugFieldName = SettingsController::getOptionFieldName('gpt_debug_mode');
$debugInputId = 'exmoau_gpt_debug_mode';
$debugLabel = esc_html__('Enable GPT Debug Mode', 'exmoment-author');
$debugDescription = esc_html__(
    'Bypass external GPT API calls and return deterministic "TEST" content for internal debugging.',
    'exmoment-author'
);
$debugFieldNameAttr = esc_attr($debugFieldName);
$debugInputIdAttr = esc_attr($debugInputId);
$debugChecked = SettingsController::isGptDebugModeEnabled();
$debugCheckedAttr = $debugChecked ? ' checked="checked"' : '';

echo '
<tr>
    <th scope="row">
        <label for="' . esc_attr($debugInputIdAttr) . '">' . esc_html($debugLabel) . '</label>
    </th>
    <td>
        <label class="exmoau-settings__checkbox">
            <input
                name="' . esc_attr($debugFieldNameAttr) . '"
                type="checkbox"
                id="' . esc_attr($debugInputIdAttr) . '"
                value="1"' . $debugCheckedAttr . '
            />
            <span>' . esc_html($debugLabel) . '</span>
        </label>
        <p class="description">' . esc_html($debugDescription) . '</p>
    </td>
</tr>
';

$weightFieldName = SettingsController::getOptionFieldName('openai_weight_key');
$weightInputId = 'exmoau_openai_weight_key';
$weightLabel = esc_html__('OpenAI weight preset', 'exmoment-author');
$weightDescription = esc_html__('Select the token weight preset for OpenAI requests. Defaults to the 2aq profile.', 'exmoment-author');
$weightFieldNameAttr = esc_attr($weightFieldName);
$weightInputIdAttr = esc_attr($weightInputId);
$selectedWeight = SettingsController::getOpenAiWeightKey();

// dd($selectedWeight);

$weightOptions = [];

foreach (GptController::getWeightsMap() as $weightKey => $maxTokens) {
    $optionKey = (string) $weightKey;
    $labelText = $optionKey;

    if (is_numeric($maxTokens)) {
        $labelText = sprintf('%s (%s tokens)', $optionKey, $maxTokens);
    }

    $weightOptions[$optionKey] = $labelText;
}

echo '<tr>';
echo '    <th scope="row">';
echo '        <label for="' . esc_attr($weightInputIdAttr) . '">' . esc_html($weightLabel) . '</label>';
echo '    </th>';
echo '    <td>';
echo '        <select name="' . esc_attr($weightFieldNameAttr) . '" id="' . esc_attr($weightInputIdAttr) . '">';
foreach ($weightOptions as $value => $labelText) {
    $selected = selected($selectedWeight, $value, false);
    echo '            <option value="' . esc_attr($value) . '" ' . esc_html($selected) . '>' . esc_html($labelText) . '</option>';
}
echo '        </select>';
echo '        <p class="description">' . esc_html($weightDescription) . '</p>';
echo '    </td>';
echo '</tr>';

$imageModel = SettingsController::getAiImageModel();
$imageGenerationEnabled = SettingsController::isAiImageGenerationEnabled();
$imageGenerationEnabledFieldName = SettingsController::getOptionFieldName('ai_image_generation_enabled');
$imageGenerationEnabledId = 'exmoau_ai_image_generation_enabled';
$imageGenerationLabel = esc_html__('Enable AI image generation', 'exmoment-author');
$imageModelFieldName = SettingsController::getOptionFieldName('ai_image_model');
$imageModelId = 'exmoau_ai_image_model';
$imageModelLabel = esc_html__('Image model', 'exmoment-author');
$imageModelDescription = esc_html__('Choose the OpenAI image model. DALL·E 3 is deprecated but remains available.', 'exmoment-author');
$imageModelOptions = [
    'dall-e-3' => esc_html__('DALL·E 3 (Deprecated)', 'exmoment-author'),
    'gpt-image-1-mini' => esc_html__('gpt-image-1-mini', 'exmoment-author'),
    'gpt-image-1' => esc_html__('GPT Image 1', 'exmoment-author'),
];

$stylePrompt = SettingsController::getAiImageStylePrompt();
$stylePromptFieldName = SettingsController::getOptionFieldName('ai_image_style_prompt');
$stylePromptId = 'exmoau_ai_image_style_prompt';
$stylePromptLabel = esc_html__('Image style prompt (prepended to excerpt)', 'exmoment-author');
$stylePromptPlaceholder = esc_html__('Fresh, green, inviting editorial scenery – soft natural light, lush greenery, optimistic and welcoming mood, clean composition that feels modern, friendly and trustworthy.', 'exmoment-author');

$imageDimensions = SettingsController::getAiImageDimensions();
$imageDimensionsFieldName = SettingsController::getOptionFieldName('ai_image_dimensions');
$imageDimensionsId = 'exmoau_ai_image_dimensions';
$imageDimensionsLabel = esc_html__('Dimensions', 'exmoment-author');
$imageDimensionsDescription = esc_html__('Choose an allowed preset for generated image dimensions.', 'exmoment-author');
$imageDimensionOptions = [
    '1024x1024' => esc_html__('1024x1024 (square)', 'exmoment-author'),
    '1536x1024' => esc_html__('1536x1024 (landscape)', 'exmoment-author'),
    '1024x1536' => esc_html__('1024x1536 (portrait)', 'exmoment-author'),
];

echo '<tr class="exmoau-settings__section-row">';
echo '<th scope="row" colspan="2">';
echo '<h2 class="exmoau-settings__section-title">' . esc_html__('Image Generation Setup', 'exmoment-author') . '</h2>';
echo '</th>';
echo '</tr>';

echo '<tr>';
echo '    <th scope="row">';
echo '        <label for="' . esc_attr($imageGenerationEnabledId) . '">' . esc_html($imageGenerationLabel) . '</label>';
echo '    </th>';
echo '    <td>';
echo '        <label class="exmoau-settings__checkbox">';
echo '            <input type="checkbox" name="' . esc_attr($imageGenerationEnabledFieldName) . '" id="' . esc_attr($imageGenerationEnabledId) . '" value="1"' . checked(true, $imageGenerationEnabled, false) . ' />';
echo '            <span>' . esc_html($imageGenerationLabel) . '</span>';
echo '        </label>';
echo '    </td>';
echo '</tr>';

echo '<tr>';
echo '    <th scope="row">';
echo '        <label for="' . esc_attr($imageModelId) . '">' . esc_html($imageModelLabel) . '</label>';
echo '    </th>';
echo '    <td>';
echo '        <select disabled name="' . esc_attr($imageModelFieldName) . '" id="' . esc_attr($imageModelId) . '">';
foreach ($imageModelOptions as $value => $labelText) {
    $selected = selected($imageModel, $value, false);
    echo '            <option value="' . esc_attr($value) . '" ' . esc_html($selected) . '>' . esc_html($labelText) . '</option>';
}
echo '        </select>';
echo '        <p class="description">' . esc_html($imageModelDescription) . '</p>';
echo '    </td>';
echo '</tr>';

echo '<tr>';
echo '    <th scope="row">';
echo '        <label for="' . esc_attr($stylePromptId) . '">' . esc_html($stylePromptLabel) . '</label>';
echo '    </th>';
echo '    <td>';
echo '        <textarea';
echo '            name="' . esc_attr($stylePromptFieldName) . '"';
echo '            id="' . esc_attr($stylePromptId) . '"';
echo '            rows="4"';
echo '            placeholder="' . esc_attr($stylePromptPlaceholder) . '"';
echo '            class="large-text"';
echo '        >' . esc_textarea($stylePrompt) . '</textarea>';
echo '    </td>';
echo '</tr>';

echo '<tr>';
echo '    <th scope="row">';
echo '        <label for="' . esc_attr($imageDimensionsId) . '">' . esc_html($imageDimensionsLabel) . '</label>';
echo '    </th>';
echo '    <td>';
echo '        <select disabled name="' . esc_attr($imageDimensionsFieldName) . '" id="' . esc_attr($imageDimensionsId) . '">';
foreach ($imageDimensionOptions as $value => $labelText) {
    $selected = selected($imageDimensions, $value, false);
    echo '            <option value="' . esc_attr($value) . '" ' . esc_html($selected) . '>' . esc_html($labelText) . '</option>';
}
echo '        </select>';
echo '        <p class="description">' . esc_html($imageDimensionsDescription) . '</p>';
echo '    </td>';
echo '</tr>';
