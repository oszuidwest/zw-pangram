<?php
/**
 * Result details view.
 *
 * @package ZW_Pangram
 */

declare(strict_types=1);

namespace ZWPangram\Admin;

use ZWPangram\Store\ItemsRepository;
use ZWPangram\Store\ResultStatus;

/**
 * Shows one stored result with Pangram's long-form assessment and per-segment breakdown.
 *
 * This is the consumer of the stored response_json column.
 */
final class ResultDetails
{
    public const VIEW = 'details';

    /**
     * Returns the details URL for a post.
     *
     * @param int $postId Post ID.
     */
    public static function url(int $postId): string
    {
        return AdminPage::url('results', ['view' => self::VIEW, 'post_id' => $postId]);
    }

    /**
     * Renders the stored result for one post.
     *
     * @param int $postId Post ID.
     */
    public static function render(int $postId): void
    {
        $row = $postId > 0 ? (new ItemsRepository())->find($postId) : null;
        echo '<h2>' . esc_html__('Result details', 'zw-pangram') . '</h2>';
        if ($row === null || $row['result_status'] === null) {
            wp_admin_notice(esc_html__('No stored Pangram result for this post.', 'zw-pangram'), ['type' => 'info']);
            self::renderBackLink();
            return;
        }
        $result = self::decode($row['response_json']);
        self::renderSummary($row, get_post($postId), $result);
        if ($row['result_status'] === ResultStatus::Ok->value) {
            self::renderSegments($result);
        }
        self::renderBackLink();
    }

    /**
     * Decodes the stored response.
     *
     * @param string|null $json Stored response_json.
     * @return array<string, mixed>|null
     */
    private static function decode(?string $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Renders the result summary table.
     *
     * @param array<string, mixed>      $row    Stored row.
     * @param \WP_Post|null             $post   Post, if it still exists.
     * @param array<string, mixed>|null $result Decoded response.
     */
    private static function renderSummary(array $row, ?\WP_Post $post, ?array $result): void
    {
        $postId = (int) $row['post_id'];
        $title = $post instanceof \WP_Post && $post->post_title !== '' ? $post->post_title : sprintf('#%d', $postId);
        $edit = $post instanceof \WP_Post ? get_edit_post_link($postId) : null;
        $view = $post instanceof \WP_Post ? get_permalink($postId) : false;
        $status = (string) $row['result_status'];
        $label = $row['prediction_short'];
        $prediction = isset($result['prediction']) && is_string($result['prediction']) ? $result['prediction'] : '';
        $scannedAt = $row['scanned_at'] === null ? '' : get_date_from_gmt((string) $row['scanned_at'], get_option('date_format') . ' ' . get_option('time_format'));
        ?>
        <table class="widefat striped zw-pangram-details">
            <tbody>
                <tr>
                    <th scope="row"><?php esc_html_e('Post', 'zw-pangram'); ?></th>
                    <td>
                        <strong><?php echo $edit ? '<a href="' . esc_url($edit) . '">' . esc_html($title) . '</a>' : esc_html($title); ?></strong>
                        <?php if ($view) : ?>
                            <a href="<?php echo esc_url($view); ?>"><?php esc_html_e('View', 'zw-pangram'); ?></a>
                        <?php endif; ?>
                        <?php if ($row['result_stale']) : ?>
                            <span class="zw-pangram-badge zw-pangram-badge-stale"><?php esc_html_e('stale', 'zw-pangram'); ?></span>
                            <span class="description"><?php esc_html_e('Content changed after this scan', 'zw-pangram'); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ($post instanceof \WP_Post) : ?>
                    <tr>
                        <th scope="row"><?php esc_html_e('Author', 'zw-pangram'); ?></th>
                        <td><?php echo esc_html(self::authorName((int) $post->post_author)); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Published', 'zw-pangram'); ?></th>
                        <td><?php echo esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $post->post_date)); ?></td>
                    </tr>
                <?php endif; ?>
                <tr>
                    <th scope="row"><?php esc_html_e('Status', 'zw-pangram'); ?></th>
                    <td>
                        <span class="zw-pangram-pill zw-pangram-pill-<?php echo esc_attr($status); ?>"><?php echo esc_html(StatusLabels::result($status)); ?></span>
                        <?php if ((string) ($row['result_error'] ?? '') !== '') : ?>
                            <span class="description"><?php echo esc_html((string) $row['result_error']); ?></span>
                        <?php endif; ?>
                        <?php if (in_array($row['queue_status'], ['pending', 'processing', 'submitted'], true)) : ?>
                            <small>(<?php echo esc_html(StatusLabels::queue((string) $row['queue_status'])); ?>)</small>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ($label !== null) : ?>
                    <tr>
                        <th scope="row"><?php esc_html_e('Label', 'zw-pangram'); ?></th>
                        <td><span class="zw-pangram-badge zw-pangram-badge-<?php echo esc_attr(strtolower((string) $label)); ?>"><?php echo esc_html(StatusLabels::label((string) $label)); ?></span></td>
                    </tr>
                <?php endif; ?>
                <?php if ($row['headline'] !== null) : ?>
                    <tr>
                        <th scope="row"><?php esc_html_e('Result', 'zw-pangram'); ?></th>
                        <td><?php echo esc_html((string) $row['headline']); ?></td>
                    </tr>
                <?php endif; ?>
                <?php if ($prediction !== '') : ?>
                    <tr>
                        <th scope="row"><?php esc_html_e('Assessment', 'zw-pangram'); ?></th>
                        <td><?php echo esc_html($prediction); ?></td>
                    </tr>
                <?php endif; ?>
                <?php if ($row['fraction_ai'] !== null) : ?>
                    <tr>
                        <th scope="row"><?php esc_html_e('Share of text', 'zw-pangram'); ?></th>
                        <td>
                            <?php
                            printf(
                                /* translators: 1: AI share, 2: AI-assisted share, 3: human share */
                                esc_html__('AI %1$s, AI-assisted %2$s, human %3$s', 'zw-pangram'),
                                esc_html(self::percent($row['fraction_ai'])),
                                esc_html(self::percent($row['fraction_ai_assisted'])),
                                esc_html(self::percent($row['fraction_human']))
                            );
                            ?>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php if (isset($result['num_ai_segments'], $result['num_ai_assisted_segments'], $result['num_human_segments'])) : ?>
                    <tr>
                        <th scope="row"><?php esc_html_e('Segments', 'zw-pangram'); ?></th>
                        <td>
                            <?php
                            printf(
                                /* translators: 1: AI segments, 2: AI-assisted segments, 3: human segments */
                                esc_html__('%1$d AI, %2$d AI-assisted, %3$d human', 'zw-pangram'),
                                (int) $result['num_ai_segments'],
                                (int) $result['num_ai_assisted_segments'],
                                (int) $result['num_human_segments']
                            );
                            ?>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php if ($row['model'] !== null) : ?>
                    <tr>
                        <th scope="row"><?php esc_html_e('Model', 'zw-pangram'); ?></th>
                        <td><?php echo esc_html((string) $row['model'] . ($row['api_version'] !== null ? ' (' . (string) $row['api_version'] . ')' : '')); ?></td>
                    </tr>
                <?php endif; ?>
                <?php if ($scannedAt !== '') : ?>
                    <tr>
                        <th scope="row"><?php esc_html_e('Scanned', 'zw-pangram'); ?></th>
                        <td><?php echo esc_html($scannedAt); ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php if ($post instanceof \WP_Post) : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="zw-pangram-inline-form">
                <?php wp_nonce_field(Actions::NONCE_RESCAN . $postId); ?>
                <input type="hidden" name="action" value="zw_pangram_rescan">
                <input type="hidden" name="post_id" value="<?php echo esc_attr((string) $postId); ?>">
                <?php submit_button(__('Rescan', 'zw-pangram'), 'secondary', 'zw_pangram_rescan_submit', false); ?>
            </form>
        <?php endif; ?>
        <?php
    }

    /**
     * Renders the per-segment breakdown.
     *
     * @param array<string, mixed>|null $result Decoded response.
     */
    private static function renderSegments(?array $result): void
    {
        echo '<h3>' . esc_html__('Segments', 'zw-pangram') . '</h3>';
        if ($result === null) {
            echo '<p>' . esc_html__('The response of this scan is not stored.', 'zw-pangram') . '</p>';
            return;
        }
        $windows = isset($result['windows']) && is_array($result['windows']) ? array_values(array_filter($result['windows'], 'is_array')) : [];
        if ($windows === []) {
            echo '<p>' . esc_html__('The stored response contains no segment breakdown.', 'zw-pangram') . '</p>';
            return;
        }
        $hasText = false;
        ?>
        <table class="widefat striped zw-pangram-segments">
            <thead><tr>
                <th scope="col">#</th>
                <th scope="col"><?php esc_html_e('Label', 'zw-pangram'); ?></th>
                <th scope="col"><?php esc_html_e('Confidence', 'zw-pangram'); ?></th>
                <th scope="col"><?php esc_html_e('AI assistance', 'zw-pangram'); ?></th>
                <th scope="col"><?php esc_html_e('Humanized', 'zw-pangram'); ?></th>
                <th scope="col"><?php esc_html_e('Words', 'zw-pangram'); ?></th>
                <th scope="col"><?php esc_html_e('Characters', 'zw-pangram'); ?></th>
                <th scope="col"><?php esc_html_e('Text', 'zw-pangram'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($windows as $i => $window) : ?>
                <?php
                $label = isset($window['label']) && is_string($window['label']) ? $window['label'] : '';
                $text = isset($window['text']) && is_string($window['text']) ? $window['text'] : '';
                $hasText = $hasText || $text !== '';
                $humanized = isset($window['is_humanized']) ? (bool) $window['is_humanized'] : null;
                ?>
                <tr>
                    <td><?php echo esc_html((string) ($i + 1)); ?></td>
                    <td><?php echo $label === '' ? '-' : '<span class="zw-pangram-badge zw-pangram-badge-' . esc_attr(self::segmentClass($label)) . '">' . esc_html(StatusLabels::segment($label)) . '</span>'; ?></td>
                    <td><?php echo esc_html(isset($window['confidence']) && is_string($window['confidence']) ? StatusLabels::confidence($window['confidence']) : '-'); ?></td>
                    <td><?php echo esc_html(self::percent($window['ai_assistance_score'] ?? null)); ?></td>
                    <td>
                        <?php
                        if ($humanized === null) {
                            echo '-';
                        } else {
                            echo esc_html($humanized ? __('Yes', 'zw-pangram') : __('No', 'zw-pangram'));
                            if (isset($window['humanizer_score']) && is_numeric($window['humanizer_score'])) {
                                echo ' ' . esc_html('(' . self::percent($window['humanizer_score']) . ')');
                            }
                        }
                        ?>
                    </td>
                    <td><?php echo esc_html(isset($window['word_count']) && is_numeric($window['word_count']) ? number_format_i18n((int) $window['word_count']) : '-'); ?></td>
                    <td><?php echo esc_html(isset($window['start_index'], $window['end_index']) && is_numeric($window['start_index']) && is_numeric($window['end_index']) ? (int) $window['start_index'] . '-' . (int) $window['end_index'] : '-'); ?></td>
                    <td class="zw-pangram-segment-text"><?php echo $text === '' ? '-' : esc_html($text); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!$hasText) : ?>
            <p class="description"><?php esc_html_e('Segment text is not stored. Enable "Store the full response" in the settings to keep it for future scans.', 'zw-pangram'); ?></p>
        <?php endif; ?>
        <?php
    }

    /** Prints the link back to the results table. */
    private static function renderBackLink(): void
    {
        echo '<p><a href="' . esc_url(AdminPage::url('results')) . '">' . esc_html__('Back to results', 'zw-pangram') . '</a></p>';
    }

    /**
     * Returns the author's display name, or a dash for posts without an author.
     *
     * @param int $authorId Author user ID.
     */
    private static function authorName(int $authorId): string
    {
        $name = $authorId > 0 ? get_the_author_meta('display_name', $authorId) : '';
        return $name !== '' ? $name : '-';
    }

    /**
     * Formats a 0-1 fraction as a percentage.
     *
     * @param mixed $fraction Fraction.
     */
    private static function percent(mixed $fraction): string
    {
        return is_numeric($fraction) ? number_format_i18n((float) $fraction * 100, 1) . '%' : '-';
    }

    /**
     * Maps a Pangram segment label to a badge class.
     *
     * @param string $label Segment label.
     */
    private static function segmentClass(string $label): string
    {
        return match ($label) {
            'AI-Generated' => 'ai',
            'AI-Assisted' => 'mixed',
            'Human Written' => 'human',
            default => 'stale',
        };
    }
}
