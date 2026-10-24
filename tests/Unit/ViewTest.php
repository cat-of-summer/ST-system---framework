<?php

namespace ST_system\Tests\Unit;

use ST_system\Config;
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
            'ui/props.php'        => '<?= \ST_system\View::prop("a.b") ?>',
            'ui/layout/index.php' => '<main><?= \ST_system\View::ui("card", ["title" => "Inner"], function () { echo \ST_system\View::ui("meta"); }) ?></main>',
            'ui/auto.php'         => '<section><?php \ST_system\View::ui("card", ["title" => "Auto"]); ?></section>',
            'ui/global.php'       => '<?= \ST_system\View::get("site.name") ?>',
            'single.php'          => 'single file view',
            'alt/x.php'           => 'alt <?= \ST_system\View::prop("v") ?>',

            // Свои props: View::prop()/props()/hasSlot() читают только кадр компонента.
            'ui/own.php'          => '<?= \ST_system\View::prop("title", "none") ?>',
            'ui/own-null.php'     => '<?= var_export(\ST_system\View::prop("v", "dflt"), true) ?>',
            'ui/props-all.php'    => '<?= json_encode(\ST_system\View::props()) ?>',
            'ui/props-split.php'  => '<?php $p = \ST_system\View::props(["size" => "m", "tip" => "n", "head" => ["title" => "T"]], $rest); echo json_encode([$p, $rest]); ?>',
            'ui/props-var.php'    => '<?= isset($props) ? "set" : "unset" ?>',
            'ui/slot-props.php'   => '<?php \ST_system\View::slot(["size" => "m"]); ?>|<?= json_encode(\ST_system\View::props()) ?>',
            'ui/has-slot.php'     => '<?= json_encode([\ST_system\View::hasSlot(), \ST_system\View::hasSlot("head")]) ?>',

            // Скоуп слотов: closure-ребёнок живёт в области владельца, как слот Astro.
            'ui/owner.php'        => '<?php \ST_system\View::ui("card", ["title" => "Card"], function () {'
                                    .' echo \ST_system\View::prop("title"), ",", json_encode(\ST_system\View::hasSlot("x")), ",";'
                                    .' \ST_system\View::ui("own");'
                                    .' \ST_system\View::ui("card", ["title" => "Deep"], function () { echo "@", \ST_system\View::prop("title"); });'
                                    .' }); ?>',
            'ui/forward.php'      => '<?php \ST_system\View::ui("slots", [], ['
                                    .' "head" => function () { \ST_system\View::slot("head"); },'
                                    .' "default" => function () { \ST_system\View::slot(); },'
                                    .' ]); ?>',
            'ui/forward-card.php' => '<?php \ST_system\View::ui("card", ["title" => \ST_system\View::prop("title")], function () { \ST_system\View::slot(); }); ?>',
            'ui/throwing.php'     => '<?php echo \ST_system\View::ui("card", function () { throw new \RuntimeException("slot failed"); }); ?>',

            // Паттерны потребителей: Wishper (components/ui/link-or-span) и CryptoDB (pages/404).
            'ui/link.php'         => '<?php \ST_system\View::ui("anchor", ["href" => "/x"], function () { echo \ST_system\View::get("label"); }); ?>',
            'ui/anchor.php'       => '<a href="<?= \ST_system\View::get("href") ?>"><?php \ST_system\View::slot(); ?></a>',
            'ui/c-page.php'       => '<?php \ST_system\View::set("head.title", "404"); \ST_system\View::ui("c-layout", function () { \ST_system\View::ui("c-header"); }); ?>',
            'ui/c-layout.php'     => '<html><?php \ST_system\View::slot(["head.title" => "Default"]); ?></html>',
            'ui/c-header.php'     => '<title><?= \ST_system\View::get("head.title") ?></title>',

            // Кеш на изолированных props.
            'ui/pcard.php'        => '<i class="<?= \ST_system\View::prop("class", "x") ?>"><?= \ST_system\View::prop("title") ?></i>',
            'ui/ppage.php'        => '<main><?php \ST_system\View::ui("pcard", ["title" => \ST_system\View::prop("t")]); \ST_system\View::ui("pcard", ["title" => "fixed", "class" => "y"]); ?></main>',
            'ui/ppage-c.php'      => '<main><?php \ST_system\View::ui("pcard", ["title" => \ST_system\View::prop("t")])->cache(); ?></main>',
            'ui/pslot.php'        => '<section><?php \ST_system\View::ui("card", ["title" => "C"], function () { echo \ST_system\View::prop("who"); })->cache(); ?></section>',
        ];

        foreach ($files as $path => $content) {
            if (!is_dir(dirname(self::$dir.'/'.$path)))
                mkdir(dirname(self::$dir.'/'.$path), 0777, true);
            file_put_contents(self::$dir.'/'.$path, $content);
        }

        View::setConfig([
            'source'       => ['ui' => self::$dir.'/ui', 'alt' => self::$dir.'/alt', 'single' => self::$dir.'/single.php'],
            'contributors' => [],
            'cache'        => ['use' => false, 'dir' => self::$dir.'/.cache'],
        ]);
        self::setStatic(View::class, 'sources', null);
    }

    public function testRendersTemplateWithProps(): void {
        $this->assertSame('<div class="card">Hello &amp; bye|</div>', (string)View::ui('card', ['title' => 'Hello & bye']));
        $this->assertSame('<div class="card">untitled|</div>', (string)View::template('ui/card'));
    }

    public function testPropDotKeys(): void {
        $this->assertSame('deep', (string)View::ui('props', ['a.b' => 'deep']));
        $this->assertSame('deep', (string)View::ui('props', ['a' => ['b' => 'deep']]));
    }

    public function testPropsVariableIsNotDefined(): void {
        $this->assertSame('unset', (string)View::ui('props-var', ['props' => 'x']));
    }

    public function testPropReadsOnlyOwnFrame(): void {
        $html = (string)View::ui('card', ['title' => 'Outer'], function () { echo View::ui('own'); });

        // В отличие от View::get() (testInnerPropsShadowOuterOnes) title родителя не протекает.
        $this->assertSame('<div class="card">Outer|none</div>', $html);
        $this->assertSame('Own', (string)View::ui('own', ['title' => 'Own']));
    }

    public function testPropIgnoresGlobals(): void {
        View::set('title', 'Global');

        try {
            $this->assertSame('none', (string)View::ui('own'));
        } finally {
            self::setStatic(View::class, 'globals', []);
        }
    }

    public function testPropDefaultOnlyForAbsentKey(): void {
        $this->assertSame("'dflt'", (string)View::ui('own-null'));
        $this->assertSame('NULL', (string)View::ui('own-null', ['v' => null]));
        $this->assertSame('false', (string)View::ui('own-null', ['v' => false]));
    }

    public function testPropsReturnsNormalizedOwnProps(): void {
        $this->assertSame('{"a":{"b":1,"c":2},"d":null}', (string)View::ui('props-all', ['a.b' => 1, 'a' => ['c' => 2], 'd' => null]));
        $this->assertSame('[]', (string)View::ui('props-all'));
    }

    public function testPropsWithDefaultsAndRest(): void {
        $html = (string)View::ui('props-split', ['tip' => null, 'head.charset' => 'utf-8', 'id' => 'x', '@click' => 'go()']);

        [$props, $rest] = json_decode($html, true);

        // Дефолт - только отсутствующим ключам (tip=null остаётся), вложенные - по листьям.
        $this->assertSame(['tip' => null, 'head' => ['charset' => 'utf-8', 'title' => 'T'], 'id' => 'x', '@click' => 'go()', 'size' => 'm'], $props);
        $this->assertSame(['id' => 'x', '@click' => 'go()'], $rest);
    }

    public function testPropsOutsideRender(): void {
        $this->assertSame('d', View::prop('title', 'd'));
        $this->assertSame([], View::props());
        $this->assertSame(['size' => 'm', 'head' => ['title' => 'T']], View::props(['size' => 'm', 'head.title' => 'T'], $rest));
        $this->assertSame([], $rest);
        $this->assertFalse(View::hasSlot());
    }

    public function testPropsSeeSlotDefaults(): void {
        $this->assertSame('|{"size":"m"}', (string)View::ui('slot-props'));
        $this->assertSame('|{"size":"xl"}', (string)View::ui('slot-props', ['size' => 'xl']));
    }

    public function testHasSlot(): void {
        $this->assertSame('[false,false]', (string)View::ui('has-slot'));
        $this->assertSame('[true,false]', (string)View::ui('has-slot', function () {}));
        $this->assertSame('[true,true]', (string)View::ui('has-slot', [], ['default' => function () {}, 'head' => function () {}]));
        $this->assertSame('[false,true]', (string)View::ui('has-slot', [], ['head' => function () {}]));
    }

    public function testSlotClosureRunsInOwnerScope(): void {
        $html = (string)View::ui('owner', ['title' => 'Owner'], ['x' => function () {}]);

        // Внутри closure - props и слоты владельца (owner), а не card, который вывел слот;
        // компонент, созданный в closure, читает свои props, а его closure - снова владельца.
        $this->assertSame('<div class="card">Card|Owner,true,none<div class="card">Deep|@Owner</div></div>', $html);
    }

    public function testSlotForwardingInsideClosure(): void {
        $html = (string)View::ui('forward', [], [
            'head'    => function () { echo 'H'; },
            'default' => function () { echo 'B'; },
        ]);

        $this->assertSame('<header>H</header>B', $html);
        $this->assertSame('<div class="card">T|<b>x</b></div>', (string)View::ui('forward-card', ['title' => 'T'], function () { echo '<b>x</b>'; }));
    }

    public function testClosureOfTopLevelViewHasNoOwner(): void {
        $html = (string)View::ui('card', ['title' => 'T'], function () { echo View::prop('title', 'no-owner'); });

        $this->assertSame('<div class="card">T|no-owner</div>', $html);
    }

    public function testSlotInsideOwnerlessClosureThrows(): void {
        $this->expectException(\LogicException::class);

        (string)View::ui('card', function () { View::slot(); });
    }

    public function testExceptionInSlotRestoresScope(): void {
        try {
            (string)View::ui('throwing');
            $this->fail('исключение слота не дошло');
        } catch (\RuntimeException $e) {
            $this->assertSame('slot failed', $e->getMessage());
        }

        $this->assertSame([], self::getStatic(View::class, 'frames'));
        $this->assertSame([], self::getStatic(View::class, 'scopes'));
        $this->assertSame('<div class="card">Outer|none</div>', (string)View::ui('card', ['title' => 'Outer'], function () { echo View::ui('own'); }));
    }

    public function testGetInsideSlotClosureStillCascades(): void {
        // Wishper, components/ui/link-or-span: closure читает label через get() мимо кадра layout.
        $this->assertSame('<a href="/x">L</a>', (string)View::ui('link', ['label' => 'L']));
    }

    public function testSlotDefaultsDoNotOverrideGlobals(): void {
        // CryptoDB, pages/404: View::set() страницы главнее дефолта slot([...]) в layout.
        try {
            $this->assertSame('<html><title>404</title></html>', (string)View::ui('c-page'));
        } finally {
            self::setStatic(View::class, 'globals', []);
        }

        $this->assertSame('<html><title>Default</title></html>', (string)View::ui('c-layout', function () { View::ui('c-header'); }));
    }

    public function testReservedNamesCannotBeSources(): void {
        $source = View::config('source');

        foreach (['props', 'prop', 'hasSlot'] as $name) {
            Config::setImmutableConfig(View::class, 'source', [$name => self::$dir.'/ui']);
            self::setStatic(View::class, 'sources', null);

            try {
                View::template($name.'/card');
                $this->fail("источник '{$name}' зарегистрирован");
            } catch (\LogicException $e) {
                $this->assertStringContainsString("source '{$name}' collides", $e->getMessage());
            } finally {
                Config::setImmutableConfig(View::class, 'source', $source);
                self::setStatic(View::class, 'sources', null);
            }
        }
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

    public function testCachedRenderOnOwnProps(): void {
        $t = bin2hex(random_bytes(4));

        $plain  = (string)View::ui('ppage', ['t' => $t]);
        $cold   = (string)View::ui('ppage', ['t' => $t])->cache();
        $warm   = (string)View::ui('ppage', ['t' => $t])->cache();
        $other  = (string)View::ui('ppage', ['t' => $t.'-2'])->cache();

        $this->assertSame('<main><i class="x">'.$t.'</i><i class="y">fixed</i></main>', $plain);
        $this->assertSame($plain, $cold);
        $this->assertSame($plain, $warm);
        $this->assertSame('<main><i class="x">'.$t.'-2</i><i class="y">fixed</i></main>', $other);
    }

    public function testCachedHoleOnOwnProps(): void {
        $t = bin2hex(random_bytes(4));

        foreach (['', '-2', ''] as $suffix) {
            $this->assertSame('<main><i class="x">'.$t.$suffix.'</i></main>', (string)View::ui('ppage-c', ['t' => $t.$suffix])->cache());
            $this->assertSame('<main><i class="x">'.$t.$suffix.'</i></main>', (string)View::ui('ppage-c', ['t' => $t.$suffix])->cache('full'));
        }
    }

    public function testCachedNodeWithClosureChildrenIsNotShared(): void {
        $t = bin2hex(random_bytes(4));

        // Ключ кеша - имя и props: без фикса второй closure получал вывод первого.
        $a = (string)View::ui('card', ['title' => $t], function () { echo 'A'; })->cache();
        $b = (string)View::ui('card', ['title' => $t], function () { echo 'B'; })->cache();

        $this->assertSame('<div class="card">'.$t.'|A</div>', $a);
        $this->assertSame('<div class="card">'.$t.'|B</div>', $b);
    }

    public function testCachedNodeKeyIncludesSerializableChildren(): void {
        $t = bin2hex(random_bytes(4));

        $a = (string)View::ui('card', ['title' => $t], self::class.'::slotA')->cache();
        $b = (string)View::ui('card', ['title' => $t], self::class.'::slotB')->cache();
        $c = (string)View::ui('card', ['title' => $t], self::class.'::slotA')->cache();

        $this->assertSame('<div class="card">'.$t.'|A</div>', $a);
        $this->assertSame('<div class="card">'.$t.'|B</div>', $b);
        $this->assertSame($a, $c);
    }

    public function testCachedNodeInUncachedParentFollowsOwnerProps(): void {
        $this->assertSame('<section><div class="card">C|X</div></section>', (string)View::ui('pslot', ['who' => 'X']));
        $this->assertSame('<section><div class="card">C|Y</div></section>', (string)View::ui('pslot', ['who' => 'Y']));
    }

    public static function slotA(): void { echo 'A'; }

    public static function slotB(): void { echo 'B'; }

    public function testMissingViewThrows(): void {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("view 'nope' not found");

        View::ui('nope');
    }

    public function testUnknownSourceThrows(): void {
        $this->expectException(\BadMethodCallException::class);

        View::nothing('x');
    }

    public function testSourceCallEventCanRewriteSourceAndPath(): void {
        View::on('source_call', function (string &$source, string &$view) {
            if ($source === 'ui' && $view === 'alias-card') $view = 'card';
            if ($source === 'ui' && $view === 'from-alt') [$source, $view] = ['alt', 'x'];
        });

        $this->assertSame('<div class="card">Alias|</div>', (string)View::ui('alias-card', ['title' => 'Alias']));
        $this->assertSame('alt 1', (string)View::ui('from-alt', ['v' => 1]));
        $this->assertSame('<div class="card">untitled|</div>', (string)View::ui('card'));
    }

    public function testSourceCallCannotBeTriggeredExternally(): void {
        $this->expectException(\LogicException::class);

        View::trigger('source_call');
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
