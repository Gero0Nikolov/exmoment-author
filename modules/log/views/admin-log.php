<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Admin log listing view for ExMoment Author.
 *
 * Expects `$data` to include filters, pagination data, level options, log entries,
 * the base page URL, and optional detail view data prepared by `LogAdminController`.
 * All output is escaped for HTML context using WordPress helpers, except for the
 * `paginate_links()` result which is trusted markup from WordPress core.
 *
 * @var array<string, mixed> $data Provides `filters`, `page_url`, `query_args`,
 *                                 `levels`, `entries`, `pagination`, and `detail`.
 */

$filters = $data['filters'];
$pageUrl = $data['page_url'];
$queryArgs = $data['query_args'];
$levels = $data['levels'];
$entries = $data['entries'];
$pagination = $data['pagination'];
$detail = $data['detail'];

$baseUrl = add_query_arg($queryArgs, $pageUrl);
$currentPage = max(1, (int) $pagination['page']);
$totalPages = max(1, (int) $pagination['total_pages']);
$totalRows = (int) $pagination['total'];
$perPage = (int) $pagination['per_page'];

$paginationLinks = paginate_links([
    'base' => add_query_arg('paged', '%#%', $baseUrl),
    'format' => '',
    'current' => $currentPage,
    'total' => max(1, $totalPages),
    'add_args' => [],
    'prev_text' => esc_html__('« Previous', 'exmoment-author'),
    'next_text' => esc_html__('Next »', 'exmoment-author'),
]);

$start = ($currentPage - 1) * $perPage + 1;
$end = min($totalRows, $currentPage * $perPage);
if ($totalRows === 0) {
    $start = 0;
    $end = 0;
}

$clearUrl = add_query_arg($queryArgs, $pageUrl);
?>

<div class="wrap exo-log">
    <h1><?php echo esc_html($data['page_title']); ?></h1>

    <form class="exo-log__filters" method="get" action="<?php echo esc_url($pageUrl); ?>">
        <input type="hidden" name="page" value="<?php echo esc_attr($queryArgs['page']); ?>" />
        <div class="exo-log__filters-row">
            <div class="exo-log__field">
                <label for="exo-log-filter-level" class="exo-log__label"><?php esc_html_e('Level', 'exmoment-author'); ?></label>
                <select id="exo-log-filter-level" name="level" class="exo-log__input">
                    <option value=""><?php esc_html_e('All levels', 'exmoment-author'); ?></option>
                    <?php foreach ($levels as $level) : ?>
                        <option value="<?php echo esc_attr($level); ?>" <?php selected($filters['level'], $level); ?>><?php echo esc_html(strtoupper($level)); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="exo-log__field">
                <label for="exo-log-filter-source" class="exo-log__label"><?php esc_html_e('Source', 'exmoment-author'); ?></label>
                <input id="exo-log-filter-source" class="exo-log__input" type="text" name="source" value="<?php echo esc_attr($filters['source']); ?>" maxlength="191" />
            </div>
            <div class="exo-log__field">
                <label for="exo-log-filter-job" class="exo-log__label"><?php esc_html_e('Job ID', 'exmoment-author'); ?></label>
                <input id="exo-log-filter-job" class="exo-log__input" type="number" min="1" step="1" name="job_id" value="<?php echo esc_attr($filters['job_id']); ?>" />
            </div>
        </div>
        <div class="exo-log__filters-row">
            <div class="exo-log__field">
                <label for="exo-log-filter-date-from" class="exo-log__label"><?php esc_html_e('Date from', 'exmoment-author'); ?></label>
                <input id="exo-log-filter-date-from" class="exo-log__input" type="date" name="date_from" value="<?php echo esc_attr($filters['date_from']); ?>" />
            </div>
            <div class="exo-log__field">
                <label for="exo-log-filter-date-to" class="exo-log__label"><?php esc_html_e('Date to', 'exmoment-author'); ?></label>
                <input id="exo-log-filter-date-to" class="exo-log__input" type="date" name="date_to" value="<?php echo esc_attr($filters['date_to']); ?>" />
            </div>
            <div class="exo-log__field">
                <label for="exo-log-filter-search" class="exo-log__label"><?php esc_html_e('Search message', 'exmoment-author'); ?></label>
                <input id="exo-log-filter-search" class="exo-log__input" type="search" name="search" value="<?php echo esc_attr($filters['search']); ?>" maxlength="128" />
            </div>
        </div>
        <div class="exo-log__actions">
            <button type="submit" class="button button-primary"><?php esc_html_e('Filter logs', 'exmoment-author'); ?></button>
            <a class="button" href="<?php echo esc_url($pageUrl); ?>"><?php esc_html_e('Reset', 'exmoment-author'); ?></a>
        </div>
    </form>

    <table class="widefat fixed striped exo-log__table">
        <thead>
            <tr>
                <th scope="col"><?php esc_html_e('ID', 'exmoment-author'); ?></th>
                <th scope="col"><?php esc_html_e('Time', 'exmoment-author'); ?></th>
                <th scope="col"><?php esc_html_e('Level', 'exmoment-author'); ?></th>
                <th scope="col"><?php esc_html_e('Source', 'exmoment-author'); ?></th>
                <th scope="col"><?php esc_html_e('Job ID', 'exmoment-author'); ?></th>
                <th scope="col"><?php esc_html_e('Message', 'exmoment-author'); ?></th>
                <th scope="col"><?php esc_html_e('Details', 'exmoment-author'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($entries)) : ?>
                <tr>
                    <td colspan="7" class="exo-log__empty"><?php esc_html_e('No log entries match the current filters.', 'exmoment-author'); ?></td>
                </tr>
            <?php else : ?>
                <?php foreach ($entries as $entry) : ?>
                    <?php
                    $timestamp = $entry['created_at'] !== '' ? (int) get_date_from_gmt($entry['created_at'], 'U') : 0;
                    $formattedTime = '';
                    if ($timestamp > 0) {
                        $formattedTime = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
                    }
                    $detailsUrl = add_query_arg(
                        array_merge($queryArgs, [
                            'paged' => $currentPage,
                            'log_id' => $entry['id'],
                        ]),
                        $pageUrl
                    );
                    $detailsUrlWithAnchor = $detailsUrl . '#exo-log-detail';
                    ?>
                    <tr>
                        <td><?php echo esc_html((string) $entry['id']); ?></td>
                        <td><?php echo esc_html($formattedTime); ?></td>
                        <td><?php echo esc_html(strtoupper($entry['level'])); ?></td>
                        <td><?php echo esc_html($entry['source']); ?></td>
                        <td>
                            <?php if ($entry['job_id'] !== null) : ?>
                                <?php echo esc_html((string) $entry['job_id']); ?>
                            <?php else : ?>
                                <?php echo esc_html('—'); ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($entry['preview']); ?></td>
                        <td><a href="<?php echo esc_url($detailsUrlWithAnchor); ?>" class="exo-log__detail-link"><?php esc_html_e('View', 'exmoment-author'); ?></a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="exo-log__pagination">
        <div class="exo-log__pagination-summary">
            <?php
            printf(
                /* translators: 1: First log entry number displayed on the current page, 2: Last log entry number displayed on the current page, 3: Total number of log entries available. */
                esc_html__('Showing %1$d–%2$d of %3$d log entries', 'exmoment-author'),
                (int) $start,
                (int) $end,
                (int) $totalRows
            );
            ?>
        </div>
        <?php if (!empty($paginationLinks)) : ?>
            <div class="tablenav">
                <div class="tablenav-pages"><?php echo wp_kses_post($paginationLinks); ?></div>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($detail['requested']) : ?>
        <div id="exo-log-detail" class="exo-log__detail" tabindex="-1">
            <div class="exo-log__detail-header">
                <h2><?php esc_html_e('Log details', 'exmoment-author'); ?></h2>
                <a class="button" href="<?php echo esc_url($clearUrl); ?>"><?php esc_html_e('Clear selection', 'exmoment-author'); ?></a>
            </div>
            <?php if (!is_array($detail['entry'])) : ?>
                <div class="notice notice-warning"><p><?php esc_html_e('The requested log entry no longer exists.', 'exmoment-author'); ?></p></div>
            <?php else : ?>
                <?php
                $detailCreated = '';
                $detailUpdated = '';
                if (!empty($detail['entry']['created_at'])) {
                    $detailCreatedTimestamp = (int) get_date_from_gmt($detail['entry']['created_at'], 'U');
                    if ($detailCreatedTimestamp > 0) {
                        $detailCreated = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $detailCreatedTimestamp);
                    }
                }

                if (!empty($detail['entry']['updated_at'])) {
                    $detailUpdatedTimestamp = (int) get_date_from_gmt($detail['entry']['updated_at'], 'U');
                    if ($detailUpdatedTimestamp > 0) {
                        $detailUpdated = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $detailUpdatedTimestamp);
                    }
                }
                ?>
                <div class="exo-log__detail-grid">
                    <div><strong><?php esc_html_e('ID', 'exmoment-author'); ?>:</strong> <?php echo esc_html((string) $detail['entry']['id']); ?></div>
                    <div><strong><?php esc_html_e('Recorded', 'exmoment-author'); ?>:</strong> <?php echo esc_html($detailCreated); ?></div>
                    <div><strong><?php esc_html_e('Level', 'exmoment-author'); ?>:</strong> <?php echo esc_html(strtoupper($detail['entry']['level'])); ?></div>
                    <div><strong><?php esc_html_e('Source', 'exmoment-author'); ?>:</strong> <?php echo esc_html($detail['entry']['source']); ?></div>
                    <div><strong><?php esc_html_e('Job ID', 'exmoment-author'); ?>:</strong>
                        <?php if ($detail['entry']['job_id'] !== null) : ?>
                            <?php echo esc_html((string) $detail['entry']['job_id']); ?>
                        <?php else : ?>
                            <?php echo esc_html('—'); ?>
                        <?php endif; ?>
                    </div>
                    <div><strong><?php esc_html_e('Updated', 'exmoment-author'); ?>:</strong> <?php echo esc_html($detailUpdated); ?></div>
                </div>
                <div class="exo-log__detail-message">
                    <h3><?php esc_html_e('Message', 'exmoment-author'); ?></h3>
                    <pre><?php echo esc_html($detail['entry']['message']); ?></pre>
                </div>
                <div class="exo-log__detail-context">
                    <h3><?php esc_html_e('Context', 'exmoment-author'); ?></h3>
                    <?php if ($detail['context']['type'] === 'empty') : ?>
                        <p><?php esc_html_e('No additional context recorded.', 'exmoment-author'); ?></p>
                    <?php else : ?>
                        <pre><?php echo esc_html($detail['context']['content']); ?></pre>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
