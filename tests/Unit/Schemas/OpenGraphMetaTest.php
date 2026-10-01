<?php

namespace ST_system\Tests\Unit\Schemas;

use ST_system\Schemas\OpenGraph\Meta;
use ST_system\Tests\TestCase;

final class OpenGraphMetaTest extends TestCase {

    public function testPrintsOpenGraphAndTwitterTags(): void {
        $html = Meta::create()->fill([
            'title'       => 'Заголовок "в кавычках" & <теги>',
            'description' => 'Описание',
            'url'         => 'https://example.com/page',
            'image'       => 'https://example.com/i.png',
            'image_width' => '1200',
        ])->print();

        $this->assertSame(implode("\n", [
            '<meta property="og:type" content="website">',
            '<meta property="og:title" content="Заголовок &quot;в кавычках&quot; &amp; &lt;теги&gt;">',
            '<meta property="og:description" content="Описание">',
            '<meta property="og:url" content="https://example.com/page">',
            '<meta property="og:image" content="https://example.com/i.png">',
            '<meta property="og:image:width" content="1200">',
            '<meta name="twitter:card" content="summary_large_image">',
            '<meta name="twitter:title" content="Заголовок &quot;в кавычках&quot; &amp; &lt;теги&gt;">',
            '<meta name="twitter:description" content="Описание">',
            '<meta name="twitter:image" content="https://example.com/i.png">',
        ]), $html);
    }

    public function testTwitterOverridesAndSkip(): void {
        $data = Meta::create()->fill([
            'type'          => 'article',
            'title'         => 'T',
            'twitter_card'  => 'summary',
            'twitter_title' => 'TT',
            'skip'          => ['og:type'],
        ])->toArray();

        $this->assertSame(['og:title' => 'T'], $data['og']);
        $this->assertSame(['twitter:card' => 'summary', 'twitter:title' => 'TT'], $data['twitter']);
    }

    public function testTitleIsRequired(): void {
        $this->expectException(\Exception::class);

        Meta::create()->fill(['description' => 'no title']);
    }
}
