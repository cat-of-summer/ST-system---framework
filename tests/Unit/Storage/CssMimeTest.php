<?php

namespace ST_system\Tests\Unit\Storage;

use ST_system\Storage\Mimes\CssMime;
use ST_system\Tests\TestCase;

final class CssMimeTest extends TestCase {

    /** @dataProvider minifyCases */
    public function testMinify(string $css, string $expected): void {
        $this->assertSame($expected, CssMime::__minify($css, []));
    }

    public function minifyCases(): array {
        return [
            'whitespace and last semicolon' => [
                ".a {\n  color : red ;\n  margin: 0 auto ;\n}\n",
                '.a{color:red;margin:0 auto}',
            ],
            'comments' => [
                "/* header ' */ .x{color:red;} /* tail */",
                '.x{color:red}',
            ],
            'combinators' => [
                '.b > .c ~ .d + .e , .f { top: -1px }',
                '.b>.c~.d+.e,.f{top:-1px}',
            ],
            'descendant pseudo-class keeps its space' => [
                '.a :hover , .b:hover { color: red !important }',
                '.a :hover,.b:hover{color:red !important}',
            ],
            'calc keeps operator spacing' => [
                '.a { width: calc(100% - 2px); height: calc( 1px + 2em ) }',
                '.a{width:calc(100% - 2px);height:calc(1px + 2em)}',
            ],
            'strings are untouched' => [
                '.a::before { content: "a , b ; c /* x */"; quotes: \'«\' \'»\' }',
                '.a::before{content:"a , b ; c /* x */";quotes:\'«\' \'»\'}',
            ],
            'urls' => [
                '.bg { background: url( \'a b.png\' ) no-repeat, url( img/x.png ) }',
                '.bg{background:url(\'a b.png\') no-repeat,url(img/x.png)}',
            ],
            'data uri with semicolons' => [
                '@font-face { src: url(data:font/woff2;base64,AA==) format("woff2") }',
                '@font-face{src:url(data:font/woff2;base64,AA==) format("woff2")}',
            ],
            'media query' => [
                '@media screen and (max-width: 600px) { .a { top: 0 } }',
                '@media screen and (max-width:600px){.a{top:0}}',
            ],
            'nth-child and shorthand slashes' => [
                'li:nth-child( 2n + 1 ) { font: 12px/1.5 "Open Sans", sans-serif; grid-area: 1 / 2 }',
                'li:nth-child(2n+1){font:12px/1.5 "Open Sans",sans-serif;grid-area:1 / 2}',
            ],
        ];
    }

    public function testMinifiedFileIsCachedByMtime(): void {
        $dir  = $this->tmpDir('css');
        $file = $this->writeFile("{$dir}/style.css", ".a { color : red ; }");

        $min = \ST_system\Storage\File::make($file)->minify();

        $this->assertSame('.a{color:red}', $min->getRaw());
        $this->assertStringEndsWith('style.min.css', $min->getPathname());

        $this->writeFile($file, '.b { top : 0 }');
        touch($file, time() + 5);
        clearstatcache();

        $this->assertSame('.b{top:0}', \ST_system\Storage\File::make($file)->minify()->getRaw());
    }
}
