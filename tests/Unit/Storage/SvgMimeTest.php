<?php

namespace ST_system\Tests\Unit\Storage;

use ST_system\Storage\File;
use ST_system\Tests\TestCase;

final class SvgMimeTest extends TestCase {

    private string $dir;

    protected function setUp(): void {
        $this->dir = $this->tmpDir('svg');

        $this->writeFile("{$this->dir}/icon.svg",
            '<?xml version="1.0"?><!-- comment -->'
            .'<svg xmlns="http://www.w3.org/2000/svg" id="icon" viewBox="0 0 10 10" class="src">'
            .'  <defs><linearGradient id="g"><stop offset="0"/></linearGradient></defs>'
            .'  <rect   width="10" height="10" fill="url(#g)" data-id="g"/>'
            .'</svg>'
        );

        $this->writeFile("{$this->dir}/star.svg",
            '<svg xmlns="http://www.w3.org/2000/svg" id="icon" viewBox="0 0 20 20"><path d="M0 0L1 1"/></svg>'
        );

        $this->writeFile("{$this->dir}/sprite.svg",
            '<svg xmlns="http://www.w3.org/2000/svg" style="display:none">'
            .'<defs><clipPath id="clip"><rect width="5" height="5"/></clipPath></defs>'
            .'<symbol id="user" viewBox="0 0 24 24" fill="none"><g clip-path="url(#clip)"><circle r="3"/></g></symbol>'
            .'<symbol id="home" viewBox="0 0 16 16"><path d="M1 1"/></symbol>'
            .'</svg>'
        );
    }

    private static function load(string $svg): \DOMXPath {
        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($svg), "Невалидный SVG: {$svg}");

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('s', 'http://www.w3.org/2000/svg');

        return $xpath;
    }

    public function testMinifyDropsCommentsAndWhitespace(): void {
        $min = File::make("{$this->dir}/icon.svg")->minify()->getRaw();

        $this->assertStringNotContainsString('<!--', $min);
        $this->assertStringNotContainsString('>  <', $min);
        $this->assertStringContainsString('<rect width="10" height="10" fill="url(#g)" data-id="g"/>', $min);
        self::load($min);
    }

    public function testExtractRewritesIdsAndReferences(): void {
        $file  = File::make("{$this->dir}/icon.svg");
        $first = $file->extract(['class' => 'icon']);
        $again = $file->extract();

        $xpath = self::load($first);
        $root  = $xpath->query('/s:svg')->item(0);
        $gradientId = $xpath->evaluate('string(//s:linearGradient/@id)');

        $this->assertSame('icon', $root->getAttribute('class'));
        $this->assertSame('0 0 10 10', $root->getAttribute('viewBox'));
        $this->assertNotSame('g', $gradientId);
        $this->assertSame("url(#{$gradientId})", $xpath->evaluate('string(//s:rect/@fill)'));
        $this->assertSame('g', $xpath->evaluate('string(//s:rect/@data-id)'));

        $this->assertNotSame($gradientId, self::load($again)->evaluate('string(//s:linearGradient/@id)'));
    }

    public function testExtractClearDropsRootAttributes(): void {
        $root = self::load(File::make("{$this->dir}/icon.svg")->extract(['clear' => true]))->query('/s:svg')->item(0);

        $this->assertFalse($root->hasAttribute('class'));
    }

    public function testCombineBuildsSpriteWithUniqueSymbolIds(): void {
        $sprite = File::make("{$this->dir}/icon.svg")->combine(['icon.svg', 'star.svg', 'sprite.svg']);
        $xpath  = self::load($sprite->getRaw());

        $ids = [];
        foreach ($xpath->query('//s:symbol') as $symbol)
            $ids[$symbol->getAttribute('id')] = $symbol->getAttribute('viewBox');

        $this->assertSame('0 0 10 10', $ids['icon']);
        $this->assertSame('0 0 20 20', $ids['icon_2']);
        $this->assertSame('0 0 24 24', $ids['user']);
        $this->assertArrayHasKey('home', $ids);
        $this->assertStringEndsWith('.combined.svg', $sprite->getPathname());
    }

    public function testBySpriteReferencesSymbol(): void {
        $html = File::make("{$this->dir}/sprite.svg")->bySprite('user', ['class' => 'icon']);

        $this->assertStringContainsString('class="icon"', $html);
        $this->assertMatchesRegularExpression('~<use xlink:href="[^"]*sprite\.svg#user"></use>~', $html);
    }

    public function testExtractSpriteInlinesSymbolWithItsDefs(): void {
        $file = File::make("{$this->dir}/sprite.svg");
        $xpath = self::load($file->extractSprite('user', ['class' => 'icon']));

        $root   = $xpath->query('/s:svg')->item(0);
        $clipId = $xpath->evaluate('string(//s:clipPath/@id)');

        $this->assertSame('0 0 24 24', $root->getAttribute('viewBox'));
        $this->assertSame('none', $root->getAttribute('fill'));
        $this->assertSame('icon', $root->getAttribute('class'));
        $this->assertNotSame('', $clipId);
        $this->assertNotSame('clip', $clipId);
        $this->assertSame("url(#{$clipId})", $xpath->evaluate('string(//s:g/@clip-path)'));

        $this->assertSame('', $file->extractSprite('missing'));
    }
}
