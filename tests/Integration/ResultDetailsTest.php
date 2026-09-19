<?php
declare(strict_types=1);

namespace ZWPangram\Tests\Integration;

use ZWPangram\Admin\ResultDetails;
use ZWPangram\Tests\Support\PluginTestCase;

final class ResultDetailsTest extends PluginTestCase
{
    public function test_requested_post_id_accepts_only_digit_strings(): void
    {
        $originalGet = $_GET;
        try {
            $_GET = ['view' => 'details', 'post_id' => '42'];
            $this->assertSame(42, ResultDetails::requestedPostId());

            $_GET['post_id'] = ['42'];
            $this->assertSame(0, ResultDetails::requestedPostId());

            $_GET['post_id'] = '42x';
            $this->assertSame(0, ResultDetails::requestedPostId());
        } finally {
            $_GET = $originalGet;
        }
    }

    public function test_renders_summary_and_segment_metadata_without_stored_text(): void
    {
        $a = $this->post('One two three four five six seven eight nine ten.', ['post_title' => 'Council debates waterfront plan']);
        $this->storeOk($a, ['response_json' => $this->json([
            ['label' => 'AI-Generated', 'confidence' => 'High', 'ai_assistance_score' => 0.91, 'start_index' => 0, 'end_index' => 21, 'word_count' => 2, 'is_humanized' => true, 'humanizer_score' => 0.8],
            ['label' => 'Human Written', 'confidence' => 'Low', 'ai_assistance_score' => 0.02, 'start_index' => 21, 'end_index' => 35, 'word_count' => 3, 'is_humanized' => false],
        ])]);

        $html = $this->render($a);

        $this->assertStringContainsString('Council debates waterfront plan', $html);
        $this->assertStringContainsString('AI Detected', $html);
        $this->assertStringContainsString('We believe that this text is a mix.', $html);
        $this->assertStringContainsString('AI 80.0%, AI-assisted 10.0%, human 10.0%', $html);
        $this->assertStringContainsString('1 AI, 0 AI-assisted, 1 human', $html);
        $this->assertStringContainsString('pangram-4 (4.0)', $html);
        $this->assertStringContainsString('AI-generated', $html);
        $this->assertStringContainsString('Human-written', $html);
        $this->assertStringContainsString('91.0%', $html);
        $this->assertStringContainsString('Yes (80.0%)', $html);
        $this->assertStringContainsString('0-21', $html);
        $this->assertStringContainsString('Segment text is not stored', $html);
        $this->assertStringContainsString('zw_pangram_rescan', $html);
    }

    public function test_renders_escaped_segment_text_when_stored(): void
    {
        $a = $this->post();
        $this->storeOk($a, ['response_json' => $this->json([
            ['label' => 'AI-Assisted', 'confidence' => 'Medium', 'ai_assistance_score' => 0.5, 'text' => 'Segment <b>one</b> & two'],
        ])]);

        $html = $this->render($a);

        $this->assertStringContainsString('Segment &lt;b&gt;one&lt;/b&gt; &amp; two', $html);
        $this->assertStringNotContainsString('<b>one</b>', $html);
        $this->assertStringNotContainsString('Segment text is not stored', $html);
    }

    public function test_failed_result_shows_error_and_no_segments(): void
    {
        $a = $this->post();
        $this->repo->writeFailed($this->submitRow($a), 'Pangram exploded', 'b1');

        $html = $this->render($a);

        $this->assertStringContainsString('Pangram exploded', $html);
        $this->assertStringContainsString('zw-pangram-pill-failed', $html);
        $this->assertStringNotContainsString('<h3>', $html);
    }

    public function test_missing_or_unscanned_post_shows_notice(): void
    {
        $this->assertStringContainsString('No stored Pangram result', $this->render(999999));
        $queued = $this->post();
        $this->repo->upsertPending([$queued], false);
        $this->assertStringContainsString('No stored Pangram result', $this->render($queued));
    }

    /** @param list<array<string, mixed>> $windows */
    private function json(array $windows): string
    {
        return (string) wp_json_encode([
            'prediction' => 'We believe that this text is a mix.',
            'num_ai_segments' => 1,
            'num_ai_assisted_segments' => 0,
            'num_human_segments' => 1,
            'windows' => $windows,
        ]);
    }

    private function render(int $postId): string
    {
        ob_start();
        ResultDetails::render($postId);
        return (string) ob_get_clean();
    }
}
