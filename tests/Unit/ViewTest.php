<?php

namespace ST_system\Tests\Unit;

use ST_system\Tests\TestCase;
use ST_system\View;

final class ViewTest extends TestCase {

    private static string $dir;

    public static function setUpBeforeClass(): void {
        self::$dir = ST_TESTS_ROOT.'/views-'.bin2hex(random_bytes(4));

        $files = [
            'ui/card.php'         => '<div class="card"><?= htmlspecialchars((string)\ST_system\View::get("title", "untitled")) ?>|<?php \ST_system\View::slot(); ?></div>',
            'ui/slots.php'        => '<header><?php \ST_system\View::slot("head"); ?></header><?php \ST_system\View::slot(); ?>',
            'ui/defaults.php'     => '<?php \ST_system\View::slot(["size" => "m"]); ?><b><?= \ST_system\View::get("size") ?></b>',
            // name() без аргумента — корневой фрейм, name(0) — текущий (docs/src/View.php.md).
            'ui/meta.php'         => '<?= \ST_system\View::name() ?>/<?= \ST_system\View::name(0) ?>@<?= \ST_system\View::deep() ?>',
            'ui/page.php'         => '<main><?= \ST_system\View::ui("card", ["title" => "Inner"], function () { echo "child"; }) ?></main>',
            'ui/props.php'        => '<?= $props["a"]["b"] ?>',
            'ui/layout/index.php' => '<main><?= \ST_system\View::ui("card", ["title" => "Inner"], function () { echo \ST_system\View::ui("meta"); }) ?></main>',
            'ui/auto.php'         => '<section><?php \ST_system\View::ui("card", ["title" => "Auto"]); ?></section>',
            'ui/global.php'       => '<?= \ST_system\View::get("site.name") ?>',
            'single.php'          => 'single file view',
        ];

        foreach ($files as $path => $content) {
            if (!is_dir(dirname(self::$dir.'/'.$path)))
                mkdir(dirname(self::$dir.'/'.$path), 0777, true);
            file_put_contents(self::$dir.'/'.$path, $content);
        }

        View::setConfig([
            'source'       => ['ui' => self::$dir.'/ui', 'single' => self::$dir.'/single.php'],
            'contributors' => [],
            'cache'        => ['use' => false, 'dir' => self::$dir.'/.cache'],
        ]);
        self::setStatic(View::class, 'sources', null);
    }

    public function testRendersTemplateWithProps(): void {
        $this->assertSame('<div class="card">Hello &amp; bye|</div>', (string)View::ui('card', ['title' => 'Hello & bye']));
        $this->assertSame('<div class="card">untitled|</div>', (string)View::template('ui/card'));
    }

    public function testPropsVariableAndDotKeys(): void {
        $this->assertSame('deep', (string)View::ui('props', ['a.b' => 'deep']));
    }

    public function testChildrenClosureIsDefaultSlot(): void {
        $html = (string)View::ui('card', ['title' => 'T'], function () { echo '<i>child</i>'; });

        $this->assertSame('<div class="card">T|<i>child</i></div>', $html);
    }

    public function testNamedSlots(): void {
        $html = (string)View::ui('slots', [], [
            'head'    => function () { echo 'H'; },
            'default' => function () { echo 'B'; },
        ]);

        $this->assertSame('<header>H</header>B', $html);
    }

    public function testSlotDefaultsDoNotOverrideProps(): void {
        $this->assertSame('<b>m</b>', (string)View::ui('defaults'));
        $this->assertSame('<b>xl</b>', (string)View::ui('defaults', ['size' => 'xl']));
    }

    public function testNestedTemplatesAndFrameInfo(): void {
        $this->assertSame('<main><div class="card">Inner|layout/meta@2</div></main>', (string)View::ui('layout'));
    }

    public function testNestedTemplateEchoesItselfWhenNotPrinted(): void {
        $this->assertSame('<section><div class="card">Auto|</div></section>', (string)View::ui('auto'));
    }

    public function testInnerPropsShadowOuterOnes(): void {
        $html = (string)View::ui('card', ['title' => 'Outer'], function () { echo View::ui('card'); });

        // Дочерний шаблон без своего title читает его из родительского кадра.
        $this->assertSame('<div class="card">Outer|<div class="card">Outer|</div></div>', $html);
    }

    public function testGlobals(): void {
        View::set(['site' => ['name' => 'Site']]);

        $this->assertSame('Site', (string)View::ui('global'));
        $this->assertSame('Site', View::get('site.name'));
        $this->assertSame('d', View::get('site.missing', 'd'));
    }

    public function testFileSource(): void {
        $this->assertSame('single file view', (string)View::single());
    }

    public function testRenderedOnlyOnce(): void {
        $view = View::ui('card');

        $this->assertNotSame('', (string)$view);
        $this->assertSame('', (string)$view);
    }

    public function testCachedRenderMatchesPlainRender(): void {
        $plain  = (string)View::ui('page');
        $cached = (string)View::ui('page')->cache();
        $replay = (string)View::ui('page')->cache();

        $this->assertSame($plain, $cached);
        $this->assertSame($plain, $replay);
        $this->assertNotEmpty(glob(self::$dir.'/.cache/*'), 'кэш скелета не записан');
    }

    public function testMissingViewThrows(): void {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("view 'nope' not found");

        View::ui('nope');
    }

    public function testUnknownSourceThrows(): void {
        $this->expectException(\BadMethodCallException::class);

        View::nothing('x');
    }

    public function testSlotOutsideRenderThrows(): void {
        $this->expectException(\LogicException::class);

        View::slot();
    }

    public function testCapture(): void {
        $this->assertSame('captured', View::capture(function () { echo 'captured'; }));
        $this->assertSame(0, View::deep());
        $this->assertSame('', View::name());
    }
}
