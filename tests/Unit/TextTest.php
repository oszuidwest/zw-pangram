<?php
declare(strict_types=1);

namespace ZWPangram\Tests\Unit;

use ZWPangram\Support\Text;

final class TextTest extends \WP_UnitTestCase
{
    public function test_strips_block_comments_shortcodes_and_tags(): void
    {
        $content = "<!-- wp:paragraph -->\n<p>Hello <strong>world</strong>&nbsp;&amp; friends</p>\n<!-- /wp:paragraph -->\n[gallery ids=\"1,2\"]<!-- wp:image {\"id\":1} --><figure><img src=\"x.jpg\" alt=\"alt\"><figcaption>Caption</figcaption></figure><!-- /wp:image -->";
        $text = Text::prepare($content);
        $this->assertSame("Hello world & friends\n\nCaption", $text);
    }

    public function test_normalizes_whitespace_and_newlines(): void
    {
        $text = Text::prepare("<p>a   b</p>\n\n\n\n<p>c\t d</p><br><br><br>e");
        $this->assertSame("a b\n\nc d\n\ne", $text);
    }

    public function test_decodes_entities_once(): void
    {
        $this->assertSame('&amp;', Text::prepare('&amp;amp;'));
    }

    public function test_scrubs_invalid_utf8(): void
    {
        $this->assertSame("ok\u{FFFD}", Text::prepare("ok\xC3"));
    }

    public function test_word_count_is_unicode_aware(): void
    {
        $this->assertSame(0, Text::wordCount(''));
        $this->assertSame(3, Text::wordCount("één  twee\ndrie"));
        $this->assertSame(1, Text::wordCount('日本語のテキスト'));
    }

    public function test_clip_respects_the_byte_limit_without_breaking_utf8(): void
    {
        $this->assertSame('short', Text::clip('short', 10));
        $this->assertSame("é...", Text::clip("éééé", 6));
        $this->assertSame(5, strlen(Text::clip("éééé", 6)));
    }

    public function test_filter_can_override_prepared_text(): void
    {
        add_filter('zw_pangram_prepared_text', static fn (): string => 'filtered');
        $this->assertSame('filtered', Text::prepare('original'));
        remove_all_filters('zw_pangram_prepared_text');
    }
}
