<?php

namespace ST_system\Tests\Unit;

use ST_system\Menu;
use ST_system\Tests\TestCase;

final class MenuTest extends TestCase {

    private function menuData(): array {
        return [
            'FIELDS'     => ['NAME' => 'root'],
            'PROPERTIES' => [],
            'ITEMS'      => [
                ['FIELDS' => ['NAME' => 'Главная', 'TYPE' => 'ITEM'], 'PROPERTIES' => ['href' => '/']],
                [
                    'FIELDS'     => ['NAME' => 'Каталог', 'TYPE' => 'SECTION'],
                    'PROPERTIES' => [],
                    'ITEMS'      => [
                        ['FIELDS' => ['NAME' => 'Товары', 'TYPE' => 'ITEM'], 'PROPERTIES' => ['href' => '/catalog']],
                    ],
                ],
                ['FIELDS' => ['NAME' => 'Пустая', 'TYPE' => 'SECTION'], 'PROPERTIES' => []],
            ],
        ];
    }

    public function testDefaultRendering(): void {
        $html = (new Menu(['menu' => $this->menuData()]))->render();

        $this->assertSame('<ul><li>Главная</li><ul><li>Товары</li></ul></ul>', $html);
    }

    public function testRenderEmptySections(): void {
        $html = (new Menu(['menu' => $this->menuData(), 'render_empty' => true]))->render();

        $this->assertSame('<ul><li>Главная</li><ul><li>Товары</li></ul><li>Пустая</li></ul>', $html);
    }

    public function testRulesPerDepth(): void {
        $item = fn($FIELDS, $PROPERTIES) => "<li><a href=\"{$PROPERTIES['href']}\">{$FIELDS['NAME']}</a></li>";

        $html = (new Menu(['menu' => $this->menuData()]))->render([
            0 => ['OPEN' => '<ul class="top">', 'ITEM' => $item, 'CLOSE' => '</ul>'],
            1 => ['OPEN' => fn($FIELDS) => "<ul data-section=\"{$FIELDS['NAME']}\">", 'ITEM' => $item, 'CLOSE' => '</ul>'],
        ]);

        $this->assertSame(
            '<ul class="top"><li><a href="/">Главная</a></li>'
            .'<ul data-section="Каталог"><li><a href="/catalog">Товары</a></li></ul></ul>',
            $html
        );
    }

    public function testDefaultRulesFromConstructor(): void {
        $html = (new Menu([
            'menu'         => $this->menuData(),
            'render_rules' => ['default' => ['OPEN' => '<ol>', 'ITEM' => '<li>*</li>', 'CLOSE' => '</ol>']],
        ]))->render();

        $this->assertSame('<ol><li>*</li><ol><li>*</li></ol></ol>', $html);
    }

    public function testMenuFromFile(): void {
        $file = $this->writeFile($this->tmpDir().'/menu.php', '<?php return '.var_export($this->menuData(), true).';');

        $this->assertSame($this->menuData(), (new Menu(['menu' => $file]))->menu);
    }

    /** @dataProvider invalidParams */
    public function testInvalidParamsThrow(array $params, string $message): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage($message);

        new Menu($params);
    }

    public function invalidParams(): array {
        return [
            [[], 'Не передано меню!'],
            [['menu' => '/no/such/menu.php'], 'файл не найден'],
            [['menu' => 42], 'Меню не оказалось массивом!'],
        ];
    }

    public function testUnknownMethodThrows(): void {
        $this->expectException(\BadMethodCallException::class);

        (new Menu(['menu' => $this->menuData()]))->draw();
    }
}
