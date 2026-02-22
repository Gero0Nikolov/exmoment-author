<?php

namespace ExMomentAuthor\Modules\Library;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use ExMomentAuthor\Core\ExMomentAuthorCoreSystem;

use DirectoryIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use WP_Error;
use ExMomentAuthor\Modules\Settings\SettingsController;

/**
 * Admin controller responsible for the ExMoment Author Library module.
 */
class LibraryController {

    private const MENU_PARENT = 'tools.php';
    private const PAGE_TITLE = 'ExMoment Author Library';
    private const MENU_TITLE = 'ExMoment Author Library';
    private const CAPABILITY = 'manage_options';
    private const MENU_SLUG = 'exmoau-library';
    private const VIEW_FILE = __DIR__ . '/views/index.php';

    private const NONCE_ACTION = 'exmoau-library';

    private const AJAX_LIST_CATEGORIES = 'exmoau_library_list_categories';
    private const AJAX_LIST_FILES = 'exmoau_library_list_files';
    private const AJAX_PREVIEW_FILE = 'exmoau_library_preview_file';
    private const AJAX_RENAME_ITEM = 'exmoau_library_rename_item';
    private const AJAX_DELETE_ITEM = 'exmoau_library_delete_item';
    private const AJAX_UPLOAD_LIBRARY = 'exmoau_library_upload';

    private const MAX_FILES_PER_PAGE = 50;
    private const PREVIEW_MAX_BYTES = 1048576; // 1 MB.
    private const MAX_UPLOAD_BYTES = 10485760; // 10 MB.

    private const ALLOWED_ITEM_PATTERN = '/^[A-Za-z0-9_.\- ]+$/';

    private const UPLOAD_FIELD = 'library_archive';
    private const META_DIRECTIVE_GENERATION_COUNT = 'exmoau_setup_directive_generation_count';
    private const DEFAULT_DIRECTIVE_GENERATION_COUNT = 1;

    /**
     * Cached repository instance for uniqueness checks.
     *
     * @var UsedArticlesRepository|null
     */
    private $usedArticlesRepository;

    /**
     * Instantiate the controller and register admin hooks.
     *
     * Hooks WordPress admin actions so each AJAX endpoint enforces the
     * `manage_options` capability, referer validation, and the
     * `exmoau-library` nonce through {@see validateAjaxRequest()}.
     *
     * @since 1.1.0
     *
     * @return void
     */
    public function __construct() {
        add_action('admin_menu', [$this, 'registerMenu']);

        add_action('wp_ajax_' . self::AJAX_LIST_CATEGORIES, [$this, 'handleListCategories']);
        add_action('wp_ajax_' . self::AJAX_LIST_FILES, [$this, 'handleListFiles']);
        add_action('wp_ajax_' . self::AJAX_PREVIEW_FILE, [$this, 'handlePreviewFile']);
        add_action('wp_ajax_' . self::AJAX_RENAME_ITEM, [$this, 'handleRenameItem']);
        add_action('wp_ajax_' . self::AJAX_DELETE_ITEM, [$this, 'handleDeleteItem']);
        add_action('wp_ajax_' . self::AJAX_UPLOAD_LIBRARY, [$this, 'handleUploadLibrary']);

    }

    /**
     * Register the Tools → ExMoment Author Library admin page.
     *
     * Adds a submenu that requires the `manage_options` capability, matching
     * the checks enforced by the AJAX endpoints.
     *
     * @since 1.1.0
     *
     * @return void
     */
    public function registerMenu() {
        add_submenu_page(
            self::MENU_PARENT,
            self::PAGE_TITLE,
            self::MENU_TITLE,
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'renderPage']
        );
    }

    /**
     * Render the admin page scaffold.
     *
     * Confirms the current user has `manage_options` before creating a view
     * model with the AJAX nonces and action names, then loads the view file.
     * Execution terminates with `wp_die()` when the capability check or view
     * lookup fails to prevent unauthorized rendering.
     *
     * @since 1.1.0
     *
     * @throws \WP_Die_Exception When the page is halted via wp_die().
     *
     * @return void
     */
    public function renderPage() {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'exmoment-author'));
        }

        $viewData = $this->getViewData();

        if (!file_exists(self::VIEW_FILE)) {
            wp_die(esc_html__('Library view missing. Please reinstall the plugin.', 'exmoment-author'));
        }

        /** @var array<string, mixed> $viewData */
        $data = $viewData;

        include self::VIEW_FILE;
    }

    /**
     * Handle AJAX requests for listing top-level categories.
     *
     * Validates the caller via {@see validateAjaxRequest()}, ensuring the
     * `manage_options` capability, a trusted referrer, and a valid
     * `exmoau-library` nonce before scanning the library root. No additional
     * request parameters are required. Responds with JSON containing a
     * `categories` array of directory names or an error payload when
     * validation fails.
     *
     * @since 1.1.0
     *
     * @throws \WP_Die_Exception When execution terminates via
     *                             wp_send_json_success() or
     *                             wp_send_json_error().
     *
     * @return void
     */
    public function handleListCategories() {
        $validation = $this->validateAjaxRequest();

        if (is_wp_error($validation)) {
            $this->sendAjaxError($validation);
        }

        $root = $this->getLibraryRoot();

        if (!is_dir($root)) {
            wp_send_json_success([
                'categories' => [],
            ]);
        }

        $categories = [];

        try {
            $iterator = new DirectoryIterator($root);
        } catch (\UnexpectedValueException $exception) {
            $this->sendAjaxError(new WP_Error(
                'exmoau_library_unreadable',
                esc_html__('Unable to read the library directory.', 'exmoment-author')
            ));
        }

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isDir()) {
                continue;
            }

            $name = $fileInfo->getFilename();

            if ($this->isHiddenName($name) || $fileInfo->isDot() || $this->isSymlink($fileInfo->getPathname())) {
                continue;
            }

            $categories[] = [
                'name' => $name,
            ];
        }

        usort($categories, static function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        wp_send_json_success([
            'categories' => $categories,
        ]);
    }

    /**
     * Handle AJAX requests for listing files inside a category.
     *
     * Confirms capability, referrer, and nonce with {@see validateAjaxRequest()}
     * before resolving the category. Accepts `category`, `page`, and
     * `per_page` values from `$_POST`; category names are normalized by
     * {@see sanitizeItemName()} while pagination parameters are cast to
     * integers and constrained to {@see self::MAX_FILES_PER_PAGE}. Responds
     * with JSON describing the selected category, pagination metadata, and a
     * sanitized `files` array; invalid requests are returned through
     * {@see sendAjaxError()}.
     *
     * @since 1.1.0
     *
     * @throws \WP_Die_Exception When execution terminates via
     *                             wp_send_json_success() or
     *                             wp_send_json_error().
     *
     * @return void
     */
    public function handleListFiles() {
        $validation = $this->validateAjaxRequest();

        if (is_wp_error($validation)) {
            $this->sendAjaxError($validation);
        }

        $categoryValue = $this->getRequestValue('category');
        if (is_array($categoryValue)) {
            $this->sendAjaxError(new WP_Error(
                'exmoau_library_invalid_category',
                esc_html__('A valid category is required.', 'exmoment-author')
            ));
        }
        $category = $this->sanitizeItemName($categoryValue);

        if ('' === $category) {
            $this->sendAjaxError(new WP_Error(
                'exmoau_library_invalid_category',
                esc_html__('A valid category is required.', 'exmoment-author')
            ));
        }

        $pageValue = $this->getRequestValue('page', 1);
        if (is_array($pageValue)) {
            $this->sendAjaxError(new WP_Error(
                'exmoau_library_invalid_page',
                esc_html__('Invalid page selection.', 'exmoment-author')
            ));
        }
        $page = max(1, (int) $pageValue);

        $perPageValue = $this->getRequestValue('per_page', self::MAX_FILES_PER_PAGE);
        if (is_array($perPageValue)) {
            $this->sendAjaxError(new WP_Error(
                'exmoau_library_invalid_per_page',
                esc_html__('Invalid items per page selection.', 'exmoment-author')
            ));
        }
        $perPage = (int) $perPageValue;
        $perPage = max(1, min(self::MAX_FILES_PER_PAGE, $perPage));

        $directory = $this->resolvePath([$category]);

        if ('' === $directory || !is_dir($directory)) {
            $this->sendAjaxError(new WP_Error(
                'exmoau_library_missing_category',
                esc_html__('The requested category does not exist.', 'exmoment-author')
            ));
        }

        $files = $this->collectFiles($directory);
        $total = count($files);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), max(1, $totalPages));

        $offset = ($page - 1) * $perPage;
        $slice = array_slice($files, $offset, $perPage);

        wp_send_json_success([
            'category'    => $category,
            'files'       => $slice,
            'page'        => $page,
            'per_page'    => $perPage,
            'total'       => $total,
            'total_pages' => $totalPages,
        ]);
    }

    /**
     * Handle AJAX requests for previewing a file.
     *
     * Requires `manage_options`, a trusted referrer, and a valid
     * `exmoau-library` nonce through {@see validateAjaxRequest()} prior to
     * interacting with the filesystem. Expects `category` and `filename`
     * parameters that are sanitized with {@see sanitizeItemName()}. Enforces
     * safeguards against hidden entries, symlinks, unsupported MIME types,
     * and previews exceeding {@see self::PREVIEW_MAX_BYTES}. Successful
     * responses include the sanitized identifiers, MIME type, and UTF-8
     * normalized file contents; failures yield structured JSON errors.
     *
     * @since 1.1.0
     *
     * @throws \WP_Die_Exception When execution terminates via
     *                             wp_send_json_success() or
     *                             wp_send_json_error().
     *
     * @return void
     */
    public function handlePreviewFile() {
        $validation = $this->validateAjaxRequest();

        if (is_wp_error($validation)) {
            $this->sendAjaxError($validation);
        }

        $categoryValue = $this->getRequestValue('category');
        $filenameValue = $this->getRequestValue('filename');
        if (is_array($categoryValue) || is_array($filenameValue)) {
            $this->sendAjaxError(new WP_Error(
                'exmoau_library_invalid_preview',
                esc_html__('Category and filename are required for previews.', 'exmoment-author')
            ));
        }
        $category = $this->sanitizeItemName($categoryValue);
        $filename = $this->sanitizeItemName($filenameValue);

        if ('' === $category || '' === $filename) {
            $this->sendAjaxError(new WP_Error(
                'exmoau_library_invalid_preview',
                esc_html__('Category and filename are required for previews.', 'exmoment-author')
            ));
        }

        if (!$this->isAllowedLibraryFilename($filename)) {
            $this->sendAjaxError(new WP_Error(
                'exmoau_library_disallowed_extension',
                esc_html__('Only .txt files can be previewed in the library.', 'exmoment-author')
            ));
        }

        $filePath = $this->resolvePath([$category, $filename]);

        if ('' === $filePath || !is_file($filePath)) {
            $this->sendAjaxError(new WP_Error(
                'exmoau_library_missing_file',
                esc_html__('The requested file does not exist.', 'exmoment-author')
            ));
        }

        if ($this->isSymlink($filePath)) {
            $this->sendAjaxError(new WP_Error(
                'exmoau_library_symlink',
                esc_html__('File previews are not available for symbolic links.', 'exmoment-author')
            ));
        }

        $size = filesize($filePath);

        if (false === $size || $size > self::PREVIEW_MAX_BYTES) {
            $this->sendAjaxError(new WP_Error(
                'exmoau_library_preview_too_large',
                esc_html__('The selected file is too large to preview.', 'exmoment-author')
            ));
        }

        $type = wp_check_filetype_and_ext($filePath, basename($filePath));
        $mime = ($type['type'] ?? '');

        if (!$this->isPreviewableMime($mime)) {
            $this->sendAjaxError(new WP_Error(
                'exmoau_library_preview_unsupported',
                esc_html__('This file type cannot be previewed.', 'exmoment-author')
            ));
        }

        $contents = file_get_contents($filePath);

        if (false === $contents) {
            $this->sendAjaxError(new WP_Error(
                'exmoau_library_preview_failed',
                esc_html__('Unable to read the requested file.', 'exmoment-author')
            ));
        }

        $encoding = 'UTF-8';

        if (function_exists('mb_detect_encoding')) {
            $detected = mb_detect_encoding($contents, 'UTF-8, ISO-8859-1, ASCII', true);

            if (false !== $detected) {
                $encoding = $detected;
            }
        }

        if ('UTF-8' !== $encoding && function_exists('mb_convert_encoding')) {
            $normalized = mb_convert_encoding($contents, 'UTF-8', $encoding);
        } else {
            $normalized = (string) $contents;
        }

        wp_send_json_success([
            'category'  => $category,
            'filename'  => $filename,
            'mime_type' => $mime,
            'content'   => $normalized,
        ]);
    }

    /**
     * Handle AJAX requests for renaming a file or category.
     *
     * Confirms capability, referrer, and `exmoau-library` nonce checks via
     * {@see validateAjaxRequest()} before processing the rename operation.
     * Sanitizes `item_type`, `current_name`, `new_name`, and (for files)
     * `category` parameters using {@see sanitizeItemType()} and
     * {@see sanitizeItemName()}, rejecting hidden names and symlinks. Emits a
     * success response with the sanitized identifiers when the rename
     * succeeds, or a JSON error payload if validation or filesystem checks
     * fail.
     *
     * @since 1.1.0
     *
     * @throws \WP_Die_Exception When execution terminates via
     *                             wp_send_json_success() or
     *                             wp_send_json_error().
     *
     * @return void
     */
    public function handleRenameItem() {
        $validation = $this->validateAjaxRequest();

        if (is_wp_error($validation)) {
            $this->sendAjaxError($validation);
        }

        $typeValue = $this->getRequestValue('item_type');
        $currentValue = $this->getRequestValue('current_name');
        $newValue = $this->getRequestValue('new_name');
        if (is_array($typeValue) || is_array($currentValue) || is_array($newValue)) {
            $this->sendAjaxError(new WP_Error(
                'exmoau_library_invalid_rename',
                esc_html__('Invalid rename request.', 'exmoment-author')
            ));
        }
        $type = $this->sanitizeItemType($typeValue);
        $currentName = $this->sanitizeItemName($currentValue);
        $newName = $this->sanitizeItemName($newValue);
        $category = '';

        if ('file' === $type) {
            $categoryValue = $this->getRequestValue('category');
            if (is_array($categoryValue)) {
                $this->sendAjaxError(new WP_Error(
                    'exmoau_library_invalid_category',
                    esc_html__('A valid category is required for file operations.', 'exmoment-author')
                ));
            }
            $category = $this->sanitizeItemName($categoryValue);

            if ('' === $category) {
                $this->sendAjaxError(new WP_Error(
                    'exmoau_library_invalid_category',
                    esc_html__('A valid category is required for file operations.', 'exmoment-author')
                ));
            }
        }

        if ('' === $type || '' === $currentName || '' === $newName) {
            $this->sendAjaxError(new WP_Error(
                'exmoau_library_invalid_rename',
                esc_html__('Invalid rename request.', 'exmoment-author')
            ));
        }

        if ('file' === $type && (!$this->isAllowedLibraryFilename($currentName) || !$this->isAllowedLibraryFilename($newName))) {
            $this->sendAjaxError(new WP_Error(
                'exmoau_library_disallowed_extension',
                esc_html__('Only .txt files can be renamed in the library.', 'exmoment-author')
            ));
        }

        if ($this->isHiddenName($newName)) {
            $this->sendAjaxError(new WP_Error(
                'exmoau_library_invalid_name',
                esc_html__('Names cannot start with a dot.', 'exmoment-author')
            ));
        }

        $sourcePath = ('category' === $type)
            ? $this->resolvePath([$currentName])
            : $this->resolvePath([$category, $currentName]);
        $destinationPath = ('category' === $type)
            ? $this->resolvePath([], $newName, true)
            : $this->resolvePath([$category], $newName, true);

        if ('' === $sourcePath || !file_exists($sourcePath)) {
            $this->sendAjaxError(new WP_Error(
                'exmoau_library_rename_missing',
                esc_html__('The selected item no longer exists.', 'exmoment-author')
            ));
        }

        if ($this->isSymlink($sourcePath)) {
            $this->sendAjaxError(new WP_Error(
                'exmoau_library_rename_symlink',
                esc_html__('Cannot rename symbolic links.', 'exmoment-author')
            ));
        }

        if ('' === $destinationPath) {
            $this->sendAjaxError(new WP_Error(
                'exmoau_library_destination_error',
                esc_html__('Unable to determine the destination path.', 'exmoment-author')
            ));
        }

        if (file_exists($destinationPath)) {
            $this->sendAjaxError(new WP_Error(
                'exmoau_library_rename_conflict',
                esc_html__('An item with that name already exists.', 'exmoment-author')
            ));
        }

        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        if (is_object($wp_filesystem) && method_exists($wp_filesystem, 'move')) {
            $result = $wp_filesystem->move($sourcePath, $destinationPath, true);
        } else {
            $result = false;
        }

        if (!$result) {
            $this->sendAjaxError(new WP_Error(
                'exmoau_library_rename_failed',
                esc_html__('Unable to rename the selected item.', 'exmoment-author')
            ));
        }

        wp_send_json_success([
            'item_type'    => $type,
            'current_name' => $currentName,
            'new_name'     => $newName,
            'category'     => ('file' === $type ? $category : ''),
        ]);
    }

    /**
     * Handle AJAX requests for deleting a file or category.
     *
     * Uses {@see validateAjaxRequest()} to enforce the capability, referrer,
     * and nonce requirements before validating `item_type`, `name`, and an
     * optional `category` parameter. Inputs are sanitized via
     * {@see sanitizeItemType()} and {@see sanitizeItemName()}, while symlinks
     * and missing entries are rejected. Returns JSON with the sanitized
     * identifiers and removal counts when successful; otherwise returns a
     * structured error payload.
     *
     * @since 1.1.0
     *
     * @throws \WP_Die_Exception When execution terminates via
     *                             wp_send_json_success() or
     *                             wp_send_json_error().
     *
     * @return void
     */
    public function handleDeleteItem() {
        $validation = $this->validateAjaxRequest();

        if (is_wp_error($validation)) {
            $this->sendAjaxError($validation);
        }

        $typeValue = $this->getRequestValue('item_type');
        $nameValue = $this->getRequestValue('name');
        if (is_array($typeValue) || is_array($nameValue)) {
            $this->sendAjaxError(new WP_Error(
                'exmoau_library_invalid_delete',
                esc_html__('Invalid delete request.', 'exmoment-author')
            ));
        }
        $type = $this->sanitizeItemType($typeValue);
        $name = $this->sanitizeItemName($nameValue);
        $category = '';

        if ('file' === $type) {
            $categoryValue = $this->getRequestValue('category');
            if (is_array($categoryValue)) {
                $this->sendAjaxError(new WP_Error(
                    'exmoau_library_invalid_category',
                    esc_html__('A valid category is required for file operations.', 'exmoment-author')
                ));
            }
            $category = $this->sanitizeItemName($categoryValue);

            if ('' === $category) {
                $this->sendAjaxError(new WP_Error(
                    'exmoau_library_invalid_category',
                    esc_html__('A valid category is required for file operations.', 'exmoment-author')
                ));
            }
        }

        if ('' === $type || '' === $name) {
            $this->sendAjaxError(new WP_Error(
                'exmoau_library_invalid_delete',
                esc_html__('Invalid delete request.', 'exmoment-author')
            ));
        }

        if ('file' === $type && !$this->isAllowedLibraryFilename($name)) {
            $this->sendAjaxError(new WP_Error(
                'exmoau_library_disallowed_extension',
                esc_html__('Only .txt files can be deleted from the library.', 'exmoment-author')
            ));
        }

        $targetPath = ('category' === $type)
            ? $this->resolvePath([$name])
            : $this->resolvePath([$category, $name]);

        if ('' === $targetPath || !file_exists($targetPath)) {
            $this->sendAjaxError(new WP_Error(
                'exmoau_library_delete_missing',
                esc_html__('The selected item no longer exists.', 'exmoment-author')
            ));
        }

        if ($this->isSymlink($targetPath)) {
            $this->sendAjaxError(new WP_Error(
                'exmoau_library_delete_symlink',
                esc_html__('Cannot delete symbolic links.', 'exmoment-author')
            ));
        }

        $result = false;
        $removedFiles = 0;

        if ('category' === $type) {
            $result = $this->deleteDirectory($targetPath, $removedFiles);
        } else {
            $result = false;
            if (file_exists($targetPath)) {
                wp_delete_file($targetPath);
                $result = !file_exists($targetPath);
                if ($result) {
                    $removedFiles = 1;
                }
            }
        }

        if (!$result) {
            $this->sendAjaxError(new WP_Error(
                'exmoau_library_delete_failed',
                esc_html__('Unable to delete the selected item.', 'exmoment-author')
            ));
        }

        wp_send_json_success([
            'item_type' => $type,
            'name'      => $name,
            'category'  => ('file' === $type ? $category : ''),
            'removed'   => $removedFiles,
        ]);
    }

    /**
     * Handle AJAX requests for uploading a new library archive.
     *
     * Executes {@see validateAjaxRequest()} to confirm the capability,
     * referrer, and `exmoau-library` nonce before processing the uploaded
     * archive from the `library_archive` file input. Validates ZIP structure,
     * enforces the 10&nbsp;MB size ceiling, and rejects archives that contain
     * disallowed characters or unsupported content. A successful import
     * responds with the sanitized category name and a user-facing message;
     * otherwise a JSON error payload is emitted.
     *
     * @since 1.1.0
     *
     * @throws \WP_Die_Exception When execution terminates via
     *                             wp_send_json_success() or
     *                             wp_send_json_error().
     *
     * @return void
     */
    public function handleUploadLibrary() {
        $nonceValid = check_ajax_referer(self::NONCE_ACTION, 'nonce', false);

        if (!$nonceValid) {
            $this->sendAjaxError(new WP_Error(
                'exmoau_library_invalid_nonce',
                esc_html__('Security check failed. Refresh the page and try again.', 'exmoment-author')
            ));
        }

        $validation = $this->validateAjaxRequest();

        if (is_wp_error($validation)) {
            $this->sendAjaxError($validation);
        }

        if (!isset($_FILES[self::UPLOAD_FIELD]) || !is_array($_FILES[self::UPLOAD_FIELD])) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
            $this->sendAjaxError(new WP_Error(
                'exmoau_library_upload_missing',
                esc_html__('Upload rejected: archive must contain one directory with .txt files only.', 'exmoment-author')
            ));
        }

        $rawFile = $_FILES[self::UPLOAD_FIELD]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
        if (
            !isset($rawFile['name'], $rawFile['tmp_name'], $rawFile['type'], $rawFile['error'], $rawFile['size']) ||
            !is_scalar($rawFile['name']) ||
            !is_scalar($rawFile['tmp_name']) ||
            !is_scalar($rawFile['type']) ||
            !is_scalar($rawFile['error']) ||
            !is_scalar($rawFile['size'])
        ) {
            $this->sendAjaxError(new WP_Error(
                'exmoau_library_upload_invalid',
                esc_html__('Upload rejected: archive must contain one directory with .txt files only.', 'exmoment-author')
            ));
        }

        $file = $this->sanitizeUploadedFile($rawFile); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated

        if (!is_array($file) || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            $this->sendAjaxError(new WP_Error(
                'exmoau_library_upload_invalid',
                esc_html__('Upload rejected: archive must contain one directory with .txt files only.', 'exmoment-author')
            ));
        }

        $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_OK);

        if (UPLOAD_ERR_OK !== $errorCode) {
            $message = $this->translateUploadErrorCode($errorCode);

            $this->sendAjaxError(new WP_Error('exmoau_library_upload_error', $message));
        }

        $size = (int) ($file['size'] ?? 0);

        if ($size <= 0) {
            $this->sendAjaxError(new WP_Error(
                'exmoau_library_upload_empty',
                esc_html__('Upload rejected: archive must contain one directory with .txt files only.', 'exmoment-author')
            ));
        }

        if ($size > self::MAX_UPLOAD_BYTES) {
            $this->sendAjaxError(new WP_Error(
                'exmoau_library_upload_oversize',
                esc_html__('Upload rejected: archive exceeds the 10 MB limit.', 'exmoment-author')
            ));
        }

        $originalName = (string) ($file['name'] ?? '');
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));

        if ('zip' !== $extension) {
            $this->sendAjaxError(new WP_Error(
                'exmoau_library_upload_type',
                esc_html__('Upload rejected: only .zip archives are supported.', 'exmoment-author')
            ));
        }

        if (!class_exists('\\ZipArchive')) {
            $this->sendAjaxError(new WP_Error(
                'exmoau_library_upload_zip_missing',
                esc_html__('Upload rejected: ZIP extension is not available on this server.', 'exmoment-author')
            ));
        }

        $temporaryFile = wp_tempnam($originalName ?: 'exmoau-library.zip');

        if (!$temporaryFile) {
            $this->sendAjaxError(new WP_Error(
                'exmoau_library_upload_tmp',
                esc_html__('Upload rejected: unable to create a temporary file.', 'exmoment-author')
            ));
        }

        $sourcePath = $file['tmp_name'];

        $filesystem = $this->getFilesystem();
        if (!($filesystem instanceof \WP_Filesystem_Base)) {
            wp_delete_file($temporaryFile);
            $this->sendAjaxError(new WP_Error(
                'exmoau_library_upload_fs',
                esc_html__('Upload rejected: unable to initialize the filesystem.', 'exmoment-author')
            ));
        }

        $moveResult = $filesystem->move($sourcePath, $temporaryFile, true);
        if (!$moveResult) {
            if (file_exists($temporaryFile)) {
                wp_delete_file($temporaryFile);
            }
            $this->sendAjaxError(new WP_Error(
                'exmoau_library_upload_move',
                esc_html__('Upload rejected: unable to process the uploaded archive.', 'exmoment-author')
            ));
        }

        $archive = new \ZipArchive();
        $openResult = $archive->open($temporaryFile);

        if (true !== $openResult) {
            wp_delete_file($temporaryFile);

            $this->sendAjaxError(new WP_Error(
                'exmoau_library_upload_open',
                esc_html__('Upload rejected: unable to read the archive.', 'exmoment-author')
            ));
        }

        $structure = $this->inspectArchiveStructure($archive);
        $archive->close();

        if (is_wp_error($structure)) {
            wp_delete_file($temporaryFile);
            $this->sendAjaxError($structure);
        }

        $targetRoot = $this->prepareLibraryRoot();

        if (is_wp_error($targetRoot)) {
            wp_delete_file($temporaryFile);
            $this->sendAjaxError($targetRoot);
        }

        $targetDirectory = rtrim($targetRoot, '/\\') . '/' . $structure['sanitized'];

        if (file_exists($targetDirectory)) {
            wp_delete_file($temporaryFile);
            $this->sendAjaxError(new WP_Error(
                'exmoau_library_upload_exists',
                esc_html__('Upload rejected: a category with that name already exists.', 'exmoment-author')
            ));
        }

        if (!function_exists('unzip_file')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $temporaryDirectory = $this->createTemporaryDirectory();

        if ('' === $temporaryDirectory) {
            wp_delete_file($temporaryFile);
            $this->sendAjaxError(new WP_Error(
                'exmoau_library_upload_temp_dir',
                esc_html__('Upload rejected: unable to prepare extraction directory.', 'exmoment-author')
            ));
        }

        $extractionResult = unzip_file($temporaryFile, $temporaryDirectory);

        if (is_wp_error($extractionResult)) {
            wp_delete_file($temporaryFile);
            $this->cleanTemporaryDirectory($temporaryDirectory);

            $message = $extractionResult->get_error_message();

            if ('' === $message) {
                $message = esc_html__('Upload rejected: unable to extract the archive.', 'exmoment-author');
            }

            $this->sendAjaxError(new WP_Error('exmoau_library_upload_extract', $message));
        }

        $originalDirectory = rtrim($structure['original'], '/');
        $extractedPath = rtrim($temporaryDirectory, '/\\') . '/' . $originalDirectory;

        if (!is_dir($extractedPath)) {
            wp_delete_file($temporaryFile);
            $this->cleanTemporaryDirectory($temporaryDirectory);

            $this->sendAjaxError(new WP_Error(
                'exmoau_library_upload_missing_directory',
                esc_html__('Upload rejected: archive must contain one directory with .txt files only.', 'exmoment-author')
            ));
        }

        $copyResult = copy_dir($extractedPath, $targetDirectory);

        if (is_wp_error($copyResult)) {
            wp_delete_file($temporaryFile);
            $this->cleanTemporaryDirectory($temporaryDirectory);

            $message = $copyResult->get_error_message();
            if ('' === $message) {
                $message = esc_html__('Upload rejected: unable to finalize the imported category.', 'exmoment-author');
            }

            $this->sendAjaxError(new WP_Error(
                'exmoau_library_upload_move_target',
                $message
            ));
        }

        $this->cleanTemporaryDirectory($temporaryDirectory);
        wp_delete_file($temporaryFile);

        $this->hardenLibraryDirectory($targetDirectory);

        wp_send_json_success([
            'category' => $structure['sanitized'],
            'message'  => esc_html__('Library imported successfully. The new category is now available.', 'exmoment-author'),
        ]);
    }

    /**
     * Persist used article metadata using the shared repository.
     *
     * Consumes sanitized file metadata produced by the surrounding library
     * workflows, which restrict names to {@see self::ALLOWED_ITEM_PATTERN},
     * reject symlinks, and enforce preview and upload size caps. Within the method,
     * directory names are additionally normalized with `sanitize_text_field()`
     * and truncated to 512 characters to guard against oversized inputs. Each
     * source path is hashed via {@see UsedArticlesRepository::getPathHash()},
     * deduplicated per request, and persisted through
     * {@see UsedArticlesRepository::markUsed()} once the repository and its
     * backing table are verified.
     *
     * @since 1.1.0
     *
     * @param int                                $jobId   Job identifier.
     * @param array<int, array<string, mixed>>   $sources Source metadata payload.
     * @param array<string, int>                 $metrics Collection metrics for logging.
     * @param array<string, mixed>               $context Optional execution context for telemetry.
     *
     * @return array<string, mixed>
     */
    public function storeUsedArticles($jobId, array $sources, array $metrics = [], array $context = []) {
        $jobId = absint($jobId);
        $summary = [
            'job_id'     => $jobId,
            'candidates' => isset($metrics['candidates_before']) ? (int) $metrics['candidates_before'] : 0,
            'excluded'   => isset($metrics['excluded_for_uniqueness']) ? (int) $metrics['excluded_for_uniqueness'] : 0,
            'selected'   => isset($metrics['selected']) ? (int) $metrics['selected'] : count($sources),
            'written'    => 0,
            'duplicates' => 0,
            'skipped'    => 0,
            'errors'     => [],
        ];

        $this->logTelemetry('ENTER', $summary, $context);

        if ($jobId < 1) {
            $error = new WP_Error(
                'exmoau_library_invalid_job_id',
                esc_html__('A valid job identifier is required to store used articles.', 'exmoment-author')
            );

            $summary['errors'][] = [
                'source' => [],
                'error'  => $error,
            ];

            $this->logTelemetry('EXIT', $summary, $context);

            return $summary;
        }

        if (empty($sources)) {
            $this->logTelemetry('EXIT', $summary, $context);

            return $summary;
        }

        $repository = $this->getUsedArticlesRepository();

        if (!($repository instanceof UsedArticlesRepository)) {
            $error = new WP_Error(
                'exmoau_library_repository_missing',
                esc_html__('The used articles repository is unavailable.', 'exmoment-author')
            );

            $summary['errors'][] = [
                'source' => [],
                'error'  => $error,
            ];

            $this->logTelemetry('EXIT', $summary, $context);

            return $summary;
        }

        if (!$repository->ensureRegistryTable()) {
            $error = new WP_Error(
                'exmoau_library_registry_unavailable',
                esc_html__('Unable to verify the used articles registry table.', 'exmoment-author')
            );

            $summary['errors'][] = [
                'source' => [],
                'error'  => $error,
            ];

            $this->logTelemetry('EXIT', $summary, $context);

            return $summary;
        }

        $seenHashes = [];

        foreach ($sources as $source) {
            if (!is_array($source)) {
                $summary['skipped']++;

                continue;
            }

            $path = isset($source['path']) ? (string) $source['path'] : '';
            if ('' === $path) {
                $summary['skipped']++;

                continue;
            }

            $directory = '';
            if (isset($source['directory']) && is_string($source['directory'])) {
                $directory = sanitize_text_field($source['directory']);
                if (function_exists('mb_substr')) {
                    $directory = mb_substr($directory, 0, 512);
                } else {
                    $directory = substr($directory, 0, 512);
                }
            }

            $filename = '';
            if (isset($source['filename']) && is_string($source['filename'])) {
                $filename = sanitize_text_field($source['filename']);
            }

            $hash = $repository->getPathHash($path);
            if ('' === $hash) {
                $summary['skipped']++;

                continue;
            }

            if (isset($seenHashes[$hash])) {
                $summary['duplicates']++;

                continue;
            }

            $seenHashes[$hash] = true;

            $meta = [
                'directory' => $directory,
                'job_id'    => $jobId,
            ];

            $result = $repository->markUsed($path, $meta);

            if (is_wp_error($result)) {
                $summary['errors'][] = [
                    'source' => [
                        'path'      => $path,
                        'directory' => $directory,
                        'filename'  => $filename,
                        'hash'      => $hash,
                    ],
                    'error'  => $result,
                ];

                continue;
            }

            $writeMetrics = $repository->getLastWriteMetrics();
            if (!empty($writeMetrics['idempotent'])) {
                $summary['duplicates']++;
            } else {
                $summary['written']++;
            }
        }

        $this->logTelemetry('EXIT', $summary, $context);

        return $summary;
    }

    /**
     * Resolve the generation count metadata for directives.
     *
     * Applies `absint()` normalization to the directive identifier, then
     * retrieves the stored generation count while reporting the data source
     * via the optional reference parameter.
     *
     * @since 1.1.0
     *
     * @param int         $directiveId Directive post identifier.
     * @param string|null $source      Reference updated to indicate the value source.
     *
     * @return int
     */
    public static function resolveGenerationCount($directiveId, &$source = null) {
        $source = 'default';
        $directiveId = absint($directiveId);
        if ($directiveId < 1) {
            return self::DEFAULT_DIRECTIVE_GENERATION_COUNT;
        }

        $stored = get_post_meta($directiveId, self::META_DIRECTIVE_GENERATION_COUNT, true);
        if (is_numeric($stored)) {
            $count = (int) $stored;
            if ($count >= self::DEFAULT_DIRECTIVE_GENERATION_COUNT) {
                $source = 'directive';

                return max(self::DEFAULT_DIRECTIVE_GENERATION_COUNT, $count);
            }
        }

        return self::DEFAULT_DIRECTIVE_GENERATION_COUNT;
    }

    /**
     * Validate AJAX requests for capability, nonce, and referrer.
     *
     * Called by every AJAX handler to enforce the `manage_options`
     * capability, confirm the referer host matches {@see home_url()}, and
     * verify the request nonce created in {@see getViewData()}. Intended to
     * run within the admin-ajax context and return early JSON errors when
     * the security requirements fail.
     *
     * @since 1.1.0
     *
     * @return true|WP_Error True when valid; otherwise a descriptive WP_Error.
     */
    private function validateAjaxRequest() {
        if (!current_user_can(self::CAPABILITY)) {
            return new WP_Error(
                'exmoau_library_forbidden',
                esc_html__('You are not allowed to perform this action.', 'exmoment-author')
            );
        }

        if (!$this->hasValidReferer()) {
            return new WP_Error(
                'exmoau_library_invalid_referer',
                esc_html__('Invalid request source.', 'exmoment-author')
            );
        }

        $nonce = $this->getRequestValue('nonce');

        if (
            !is_string($nonce) ||
            !wp_verify_nonce($nonce, self::NONCE_ACTION)
        ) {
            return new WP_Error(
                'exmoau_library_invalid_nonce',
                esc_html__('Security check failed. Refresh the page and try again.', 'exmoment-author')
            );
        }

        return true;
    }

    /**
     * Send a JSON error response and halt execution.
     *
     * @param WP_Error $error Validated error object to send to the requester.
     *
     * @return void
     */
    private function sendAjaxError(WP_Error $error) {
        wp_send_json_error([
            'code'    => $error->get_error_code(),
            'message' => $error->get_error_message(),
        ]);
    }

    /**
     * Sanitize a request value from wp_unslash($_POST[...]).
     *
     * Strings are trimmed and sanitized, numeric scalars are normalized with
     * absint(), and arrays are sanitized recursively.
     *
     * @param mixed $value Raw value.
     *
     * @return mixed Sanitized value.
     */
    private function sanitizeRequestValue($value) {
        if (is_array($value)) {
            return array_map([$this, 'sanitizeRequestValue'], $value);
        }

        if (is_numeric($value)) {
            return absint($value);
        }

        if (is_string($value)) {
            return sanitize_text_field(trim($value));
        }

        return $value;
    }

    /**
     * Retrieve a WP_Filesystem instance when available.
     *
     * @return \WP_Filesystem_Base|null Filesystem instance or null on failure.
     */
    private function getFilesystem() {
        global $wp_filesystem; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

        if ($wp_filesystem instanceof \WP_Filesystem_Base) {
            return $wp_filesystem;
        }

        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        if (!WP_Filesystem()) {
            return null;
        }

        return ($wp_filesystem instanceof \WP_Filesystem_Base) ? $wp_filesystem : null;
    }

    /**
     * Sanitize uploaded file array values.
     *
     * @param array<string, mixed> $file Raw $_FILES entry.
     *
     * @return array<string, mixed> Sanitized file data.
     */
    private function sanitizeUploadedFile(array $file) {
        return [
            'name'     => isset($file['name']) ? sanitize_file_name(wp_unslash((string) $file['name'])) : '',
            'tmp_name' => isset($file['tmp_name']) ? (string) wp_unslash($file['tmp_name']) : '',
            'type'     => isset($file['type']) ? sanitize_text_field(wp_unslash((string) $file['type'])) : '',
            'error'    => isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE,
            'size'     => isset($file['size']) ? (int) $file['size'] : 0,
        ];
    }

    /**
     * Retrieve a request value from $_POST.
     *
     * Normalizes data received from admin-ajax submissions. Values are
     * unslashed, sanitized, and normalized per type before use. Only accesses
     * `$_POST` because every AJAX action is registered via `wp_ajax_` with
     * admin-only execution.
     *
     * @param string     $key     Request key to retrieve.
     * @param mixed|null $default Default value when key is missing.
     *
     * @return mixed
     */
    private function getRequestValue($key, $default = null) {
        if (!isset($_POST[$key])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return $default;
        }

        $value = wp_unslash($_POST[$key]); // phpcs:ignore WordPress.Security.NonceVerification.Missing

        return $this->sanitizeRequestValue($value);
    }

    /**
     * Build view data for the admin template.
     *
     * Provides nonce, AJAX action identifiers, and limit metadata consumed by
     * the administrative UI. The nonce is later validated by
     * {@see validateAjaxRequest()} to secure subsequent requests.
     *
     * @return array<string, mixed>
     */
    private function getViewData() {
        return [
            'nonce'   => wp_create_nonce(self::NONCE_ACTION),
            'actions' => [
                'listCategories' => self::AJAX_LIST_CATEGORIES,
                'listFiles'      => self::AJAX_LIST_FILES,
                'previewFile'    => self::AJAX_PREVIEW_FILE,
                'renameItem'     => self::AJAX_RENAME_ITEM,
                'deleteItem'     => self::AJAX_DELETE_ITEM,
                'uploadLibrary'  => self::AJAX_UPLOAD_LIBRARY,
            ],
            'limits'  => [
                'perPage' => self::MAX_FILES_PER_PAGE,
                'preview' => self::PREVIEW_MAX_BYTES,
                'upload'  => self::MAX_UPLOAD_BYTES,
            ],
        ];
    }

    /**
     * Determine the root uploads path for the library.
     *
     * Returns the uploads directory used by the plugin or an empty string
     * when unavailable or misconfigured.
     *
     * @return string
     */
    private function getLibraryRoot() {
        $uploads = wp_get_upload_dir();

        if (!is_array($uploads) || !empty($uploads['error']) || empty($uploads['basedir'])) {
            return '';
        }

        return rtrim($uploads['basedir'], '/\\') . '/exmoau-library';
    }

    /**
     * Resolve a path inside the library, validating containment.
     *
     * Ensures all resolved paths remain within the library root to prevent
     * traversal. When `$allowNonExisting` is true, paths that do not yet
     * exist are allowed so long as their normalized location still resides
     * under the library root.
     *
     * @param array<int, string> $segments          Path segments relative to the library root.
     * @param string             $leaf              Optional final segment when renaming.
     * @param bool               $allowNonExisting  Whether to allow non-existing paths for write operations.
     *
     * @return string Absolute path within the library or an empty string when invalid.
     */
    private function resolvePath(array $segments, $leaf = '', $allowNonExisting = false) {
        $base = $this->getLibraryRoot();

        if ('' === $base) {
            return '';
        }

        $parts = array_map(static function ($segment) {
            return trim($segment, '/\\');
        }, $segments);

        if ('' !== $leaf) {
            $parts[] = trim($leaf, '/\\');
        }

        $relativePath = implode('/', array_filter($parts, static function ($value) {
            return '' !== $value;
        }));

        $candidate = rtrim($base, '/\\');

        if ('' !== $relativePath) {
            $candidate .= '/' . $relativePath;
        }

        $realBase = realpath($base);

        if (false === $realBase) {
            return '';
        }

        $normalizedBase = $this->normalizePath($realBase) . '/';

        $realCandidate = realpath($candidate);

        if (false === $realCandidate) {
            if (!$allowNonExisting) {
                return (file_exists($candidate) ? $candidate : '');
            }

            $candidateNormalized = $this->normalizePath($candidate) . '/';

            if (0 !== strpos($candidateNormalized, $normalizedBase)) {
                return '';
            }

            return $candidate;
        }

        $normalizedCandidate = $this->normalizePath($realCandidate) . '/';

        if (0 !== strpos($normalizedCandidate, $normalizedBase)) {
            return '';
        }

        return $realCandidate;
    }

    /**
     * Collect files inside a directory and return normalized metadata.
     *
     * @param string $directory Absolute directory path.
     *
     * @return array<int, array<string, mixed>>
     */
    private function collectFiles($directory) {
        $files = [];

        $enforceUniqueness = SettingsController::isMixtureUniquenessEnabled();
        $usedRepository = $enforceUniqueness ? $this->getUsedArticlesRepository() : null;

        try {
            $iterator = new DirectoryIterator($directory);
        } catch (\UnexpectedValueException $exception) {
            return [];
        }

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            $name = $fileInfo->getFilename();
            $path = $fileInfo->getPathname();

            if ($this->isHiddenName($name) || $this->isSymlink($path)) {
                continue;
            }

            if (!$this->isAllowedLibraryFilename($name)) {
                continue;
            }

            if ($usedRepository instanceof UsedArticlesRepository && $usedRepository->isUsed($path)) {
                continue;
            }

            $files[] = [
                'name'     => $name,
                'size'     => $fileInfo->getSize(),
                'modified' => $fileInfo->getMTime(),
            ];
        }

        usort($files, static function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        return $files;
    }

    /**
     * Delete a directory recursively.
     *
     * @param string $directory    Absolute directory path.
     * @param int    $removedFiles Reference counter for removed files.
     *
     * @return bool True when the directory is fully removed.
     */
    private function deleteDirectory($directory, &$removedFiles = 0) {
        if (!is_dir($directory)) {
            return false;
        }

        $filesystem = $this->getFilesystem();

        if (!($filesystem instanceof \WP_Filesystem_Base)) {
            return false;
        }

        $iterator = new RecursiveDirectoryIterator(
            $directory,
            RecursiveDirectoryIterator::SKIP_DOTS
        );
        $files = new RecursiveIteratorIterator(
            $iterator,
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            $path = $file->getRealPath();

            if (false === $path) {
                continue;
            }

            if ($file->isDir()) {
                $filesystem->delete($path, false, 'd');
            } else {
                if (!is_link($path)) {
                    if ($filesystem->delete($path, false, 'f')) {
                        $removedFiles++;
                    }
                }
            }
        }

        return $filesystem->delete($directory, false, 'd');
    }

    /**
     * Retrieve the shared used-articles repository instance.
     *
     * Caches the repository loaded via {@see ExMomentAuthorCoreSystem::autoload()}
     * to support uniqueness checks and persistence without repeated lookups.
     *
     * @return UsedArticlesRepository|null
     */
    private function getUsedArticlesRepository() {
        if ($this->usedArticlesRepository instanceof UsedArticlesRepository) {
            return $this->usedArticlesRepository;
        }

        $core = ExMomentAuthorCoreSystem::getInstance();
        $repository = $core->getModule('UsedArticlesRepository');

        if (!($repository instanceof UsedArticlesRepository)) {
            $core->autoload();
            $repository = $core->getModule('UsedArticlesRepository');
        }

        if ($repository instanceof UsedArticlesRepository) {
            $this->usedArticlesRepository = $repository;
        } else {
            $this->usedArticlesRepository = null;
        }

        return $this->usedArticlesRepository;
    }

    /**
     * Determine if a filesystem name should be treated as hidden.
     *
     * @param string $name Filesystem entry name.
     *
     * @return bool True when the name is empty or dot-prefixed.
     */
    private function isHiddenName($name) {
        return ('' === $name || '.' === $name[0]);
    }

    /**
     * Determine if a path is a symbolic link.
     *
     * @param string $path Path to examine.
     *
     * @return bool True when the path is a link.
     */
    private function isSymlink($path) {
        return (is_link($path));
    }

    /**
     * Normalize a filesystem path for safe string comparisons.
     *
     * Converts backslashes to forward slashes and trims trailing slashes to
     * support consistent comparisons during traversal checks.
     *
     * @param string $path Filesystem path.
     *
     * @return string Normalized path string.
     */
    private function normalizePath($path) {
        $normalized = str_replace('\\', '/', $path);

        return rtrim($normalized, '/');
    }

    /**
     * Emit WP_DEBUG-gated telemetry for used article persistence.
     *
     * @param string               $stage   Telemetry stage label.
     * @param array<string, mixed> $summary Summary payload.
     * @param array<string, mixed> $context Optional execution context.
     *
     * @return void
     */
    private function logTelemetry($stage, array $summary, array $context = []) {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        $stage = strtoupper((string) $stage);
        if ('' === $stage) {
            $stage = 'EXIT';
        }

        $jobId = isset($summary['job_id']) ? (int) $summary['job_id'] : 0;
        $candidates = isset($summary['candidates']) ? (int) $summary['candidates'] : 0;
        $excluded = isset($summary['excluded']) ? (int) $summary['excluded'] : 0;
        $selected = isset($summary['selected']) ? (int) $summary['selected'] : 0;
        $written = isset($summary['written']) ? (int) $summary['written'] : 0;
        $duplicates = isset($summary['duplicates']) ? (int) $summary['duplicates'] : 0;
        $skipped = isset($summary['skipped']) ? (int) $summary['skipped'] : 0;
        $errors = isset($summary['errors']) && is_array($summary['errors']) ? count($summary['errors']) : 0;

        $trigger = '';
        if (isset($context['trigger'])) {
            $trigger = sanitize_key((string) $context['trigger']);
        }

        $suffix = ('' !== $trigger) ? ' trigger=' . $trigger : '';

        $this->logDebug(
            'Library used-articles %s: job=%d candidates=%d excluded=%d selected=%d written=%d duplicates=%d skipped=%d errors=%d%s',
            $stage,
            $jobId,
            $candidates,
            $excluded,
            $selected,
            $written,
            $duplicates,
            $skipped,
            $errors,
            $suffix
        );
    }

    /**
     * Emit a debug log entry when WP_DEBUG is enabled.
     *
     * @param string $message Message template.
     * @param mixed  ...$args Template arguments.
     *
     * @return void
     */
    private function logDebug($message, ...$args) {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        if (!is_string($message) || '' === $message) {
            return;
        }

        if (!empty($args)) {
            $message = vsprintf($message, $args);
        }

        error_log('ExMoment Author: ' . $message); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
    }

    /**
     * Sanitize and validate an item name against the allowed pattern.
     *
     * @param string|mixed $value Raw item name value.
     *
     * @return string Sanitized name or empty string when invalid.
     */
    private function sanitizeItemName($value) {
        if (!is_string($value)) {
            return '';
        }

        $sanitized = trim(wp_strip_all_tags($value));

        if ('' === $sanitized) {
            return '';
        }

        if (!preg_match(self::ALLOWED_ITEM_PATTERN, $sanitized)) {
            return '';
        }

        if (
            strpos($sanitized, '..') !== false ||
            false !== strpos($sanitized, '/') ||
            false !== strpos($sanitized, '\\')
        ) {
            return '';
        }

        return $sanitized;
    }

    /**
     * Sanitize the requested item type.
     *
     * @param string|mixed $value Raw request value.
     *
     * @return string Allowed type slug or empty string when invalid.
     */
    private function sanitizeItemType($value) {
        $type = strtolower((string) $value);

        return (in_array($type, ['category', 'file'], true) ? $type : '');
    }

    /**
     * Determine whether the supplied MIME type can be previewed.
     *
     * @param string $mime Mime type string.
     *
     * @return bool True when the type is whitelisted for previews.
     */
    private function isPreviewableMime($mime) {
        if (empty($mime)) {
            return false;
        }

        if (0 === strpos($mime, 'text/')) {
            return true;
        }

        $allowed = [
            'application/json',
            'application/xml',
            'application/javascript',
            'application/x-javascript',
            'application/xhtml+xml',
            'application/rss+xml',
            'application/x-httpd-php',
        ];

        return in_array(strtolower($mime), $allowed, true);
    }

    /**
     * Return the list of allowed library file extensions.
     *
     * @return array<int, string>
     */
    private function getAllowedLibraryFileExtensions() {
        return [
            'txt',
        ];
    }

    /**
     * Extract the normalized file extension for a library filename.
     *
     * @param string $filename File name to inspect.
     *
     * @return string Normalized extension or empty string when unavailable.
     */
    private function getLibraryFileExtension($filename) {
        if (!is_string($filename) || '' === $filename) {
            return '';
        }

        return strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
    }

    /**
     * Determine if the filename matches the allowed extension list.
     *
     * @param string $filename File name to validate.
     *
     * @return bool True when the filename has an allowlisted extension.
     */
    private function isAllowedLibraryFilename($filename) {
        $extension = $this->getLibraryFileExtension($filename);

        if ('' === $extension) {
            return false;
        }

        return in_array($extension, $this->getAllowedLibraryFileExtensions(), true);
    }

    /**
     * Translate a PHP upload error code into a localized message.
     *
     * @param int $code Upload error code.
     *
     * @return string Localized message explaining the upload failure.
     */
    private function translateUploadErrorCode($code) {
        switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return esc_html__('Upload rejected: the archive exceeds the allowed size.', 'exmoment-author');
            case UPLOAD_ERR_PARTIAL:
                return esc_html__('Upload rejected: the archive was only partially uploaded.', 'exmoment-author');
            case UPLOAD_ERR_NO_FILE:
                return esc_html__('Upload rejected: no file was received.', 'exmoment-author');
            case UPLOAD_ERR_NO_TMP_DIR:
                return esc_html__('Upload rejected: missing a temporary folder.', 'exmoment-author');
            case UPLOAD_ERR_CANT_WRITE:
                return esc_html__('Upload rejected: failed to write the archive to disk.', 'exmoment-author');
            case UPLOAD_ERR_EXTENSION:
                return esc_html__('Upload rejected: a server extension blocked the upload.', 'exmoment-author');
            default:
                return esc_html__('Upload rejected: unexpected upload error.', 'exmoment-author');
        }
    }

    /**
     * Validate the structure of an uploaded archive.
     *
     * @param \ZipArchive $archive Opened archive instance.
     *
     * @return array<string, string>|WP_Error Structured directory metadata or error details.
     */
    private function inspectArchiveStructure(\ZipArchive $archive) {
        $totalFiles = $archive->numFiles;
        $directories = [];
        $txtFiles = 0;

        for ($index = 0; $index < $totalFiles; $index++) {
            $stat = $archive->statIndex($index);

            if (!is_array($stat) || !isset($stat['name'])) {
                continue;
            }

            $rawName = (string) $stat['name'];

            if ('' === $rawName) {
                continue;
            }

            $normalized = str_replace('\\', '/', $rawName);

            if (false !== strpos($normalized, "\0") || false !== strpos($normalized, '../')) {
                return new WP_Error(
                    'exmoau_library_upload_traversal',
                    esc_html__('Upload rejected: archive paths are not allowed to contain traversal segments.', 'exmoment-author')
                );
            }

            $normalized = ltrim($normalized, '/');

            if ('' === $normalized || '__MACOSX/' === substr($normalized, 0, 9)) {
                continue;
            }

            $isDirectory = ('/' === substr($normalized, -1));
            $segments = array_values(array_filter(explode('/', $normalized), static function ($segment) {
                return '' !== $segment;
            }));

            if (empty($segments)) {
                continue;
            }

            $topLevel = $segments[0];

            if ('__MACOSX' === $topLevel) {
                continue;
            }

            if ($this->isHiddenName($topLevel)) {
                return new WP_Error(
                    'exmoau_library_upload_hidden',
                    esc_html__('Upload rejected: hidden directories are not supported.', 'exmoment-author')
                );
            }

            if ($isDirectory && count($segments) > 1) {
                return new WP_Error(
                    'exmoau_library_upload_nested_directory',
                    esc_html__('Upload rejected: nested directories are not allowed.', 'exmoment-author')
                );
            }

            $directories[$topLevel] = true;

            if ($isDirectory) {
                continue;
            }

            if (count($segments) !== 2) {
                return new WP_Error(
                    'exmoau_library_upload_depth',
                    esc_html__('Upload rejected: files must live directly inside the category directory.', 'exmoment-author')
                );
            }

            $fileName = $segments[1];

            if ($this->isSystemFilename($fileName)) {
                continue;
            }

            $extension = strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));

            if ('txt' !== $extension) {
                return new WP_Error(
                    'exmoau_library_upload_type_invalid',
                    esc_html__('Upload rejected: archive must contain one directory with .txt files only.', 'exmoment-author')
                );
            }

            $txtFiles++;
        }

        $validDirectories = array_keys($directories);

        if (empty($validDirectories)) {
            return new WP_Error(
                'exmoau_library_upload_empty_archive',
                esc_html__('Upload rejected: archive must contain one directory with .txt files only.', 'exmoment-author')
            );
        }

        if (count($validDirectories) > 1) {
            return new WP_Error(
                'exmoau_library_upload_multiple_directories',
                esc_html__('Upload rejected: archive must contain one directory with .txt files only.', 'exmoment-author')
            );
        }

        if ($txtFiles < 1) {
            return new WP_Error(
                'exmoau_library_upload_no_text',
                esc_html__('Upload rejected: archive must contain one directory with .txt files only.', 'exmoment-author')
            );
        }

        $original = $validDirectories[0];
        $sanitized = $this->sanitizeDirectorySlug($original);

        if ('' === $sanitized) {
            return new WP_Error(
                'exmoau_library_upload_directory_invalid',
                esc_html__('Upload rejected: archive directory name is not allowed.', 'exmoment-author')
            );
        }

        return [
            'original'  => $original,
            'sanitized' => $sanitized,
        ];
    }

    /**
     * Prepare the library root ensuring it exists and is hardened.
     *
     * @return string|WP_Error Absolute root path or a WP_Error when creation fails.
     */
    private function prepareLibraryRoot() {
        $root = $this->getLibraryRoot();

        if ('' === $root) {
            return new WP_Error(
                'exmoau_library_upload_root_missing',
                esc_html__('Upload rejected: unable to determine the uploads directory.', 'exmoment-author')
            );
        }

        if (!is_dir($root) && !wp_mkdir_p($root)) {
            return new WP_Error(
                'exmoau_library_upload_root_unwritable',
                esc_html__('Upload rejected: unable to create the uploads directory.', 'exmoment-author')
            );
        }

        $this->hardenLibraryDirectory($root);

        return $root;
    }

    /**
     * Create a unique temporary directory for archive extraction.
     *
     * @return string Temporary directory path or empty string on failure.
     */
    private function createTemporaryDirectory() {
        $tempFile = wp_tempnam('exmoau-library');

        if (false === $tempFile) {
            return '';
        }

        if (file_exists($tempFile)) {
            wp_delete_file($tempFile);
        }

        $directory = $tempFile . '_dir';

        if (!wp_mkdir_p($directory)) {
            return '';
        }

        return $directory;
    }

    /**
     * Remove a temporary directory and its contents.
     *
     * @param string $directory Directory to remove.
     *
     * @return void
     */
    private function cleanTemporaryDirectory($directory) {
        if ('' === $directory || !file_exists($directory)) {
            return;
        }

        if (is_dir($directory)) {
            $removed = 0;
            $this->deleteDirectory($directory, $removed);
        } else {
            wp_delete_file($directory);
        }
    }

    /**
     * Create security files in the provided directory.
     *
     * @param string $directory Absolute directory path.
     *
     * @return void
     */
    private function hardenLibraryDirectory($directory) {
        if ('' === $directory || !is_dir($directory)) {
            return;
        }

        $normalized = rtrim($directory, '/\\');

        $indexPath = $normalized . '/index.php';
        $filesystem = $this->getFilesystem();

        if (!file_exists($indexPath)) {
            $contents = "<?php\n";
            $contents .= "// Silence is golden.\n";
            if ($filesystem instanceof \WP_Filesystem_Base) {
                $filesystem->put_contents($indexPath, $contents, FS_CHMOD_FILE);
            } else {
                file_put_contents($indexPath, $contents);
                wp_chmod_file($indexPath);
            }
        }

        $htaccessPath = $normalized . '/.htaccess';

        if (!file_exists($htaccessPath)) {
            $rules = [];
            $rules[] = 'Options -Indexes';
            $rules[] = '<Files *.php>';
            $rules[] = '    Require all denied';
            $rules[] = '</Files>';
            $rules[] = '<IfModule mod_php.c>';
            $rules[] = '    php_flag engine off';
            $rules[] = '</IfModule>';
            $rules[] = '<IfModule mod_php7.c>';
            $rules[] = '    php_flag engine off';
            $rules[] = '</IfModule>';
            $rules[] = '<IfModule mod_php8.c>';
            $rules[] = '    php_flag engine off';
            $rules[] = '</IfModule>';

            if ($filesystem instanceof \WP_Filesystem_Base) {
                $filesystem->put_contents($htaccessPath, implode("\n", $rules) . "\n", FS_CHMOD_FILE);
            } else {
                file_put_contents($htaccessPath, implode("\n", $rules) . "\n");
                wp_chmod_file($htaccessPath);
            }
        }
    }

    /**
     * Determine whether a filename should be treated as a system artifact.
     *
     * @param string $name File name.
     *
     * @return bool True when the filename should be skipped during import.
     */
    private function isSystemFilename($name) {
        $lower = strtolower($name);

        if (in_array($lower, ['.ds_store', 'thumbs.db'], true)) {
            return true;
        }

        return (0 === strpos($lower, '._'));
    }

    /**
     * Sanitize the extracted directory name for filesystem usage.
     *
     * @param string $name Raw directory name from the archive.
     *
     * @return string Cleaned directory slug or empty string when disallowed.
     */
    private function sanitizeDirectorySlug($name) {
        $clean = preg_replace('/[^A-Za-z0-9_\-]/', '-', $name);

        if (null === $clean) {
            return '';
        }

        $clean = preg_replace('/-+/', '-', $clean);

        if (null === $clean) {
            return '';
        }

        $clean = trim($clean, '-');

        return $clean;
    }

    /**
     * Confirm the AJAX request originated from the current host.
     *
     * Checks the request referer header to ensure admin-ajax calls originate
     * from the same host as {@see home_url()}. Complements nonce validation
     * inside {@see validateAjaxRequest()} to prevent CSRF.
     *
     * @return bool True when the referer host matches the site host.
     */
    private function hasValidReferer() {
        if (empty($_SERVER['HTTP_REFERER'])) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
            return false;
        }

        $referer = esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        if ('' === $referer) {
            return false;
        }

        $refererHost = wp_parse_url($referer, PHP_URL_HOST);
        $siteHost = wp_parse_url(home_url(), PHP_URL_HOST);

        if (empty($refererHost) || empty($siteHost)) {
            return false;
        }

        return (strtolower($refererHost) === strtolower($siteHost));
    }
}
