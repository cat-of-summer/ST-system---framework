<?php

namespace ST_system\Tests\Unit\Storage;

use ST_system\Storage\File;
use ST_system\Tests\TestCase;

final class JsMimeTest extends TestCase {

    private function minify(string $js): string {
        $file = $this->writeFile($this->tmpDir('js').'/script.js', $js);

        return File::make($file)->minify()->getRaw();
    }

    /** @dataProvider minifyCases */
    public function testMinify(string $js, string $expected): void {
        $this->assertSame($expected, $this->minify($js));
    }

    public function minifyCases(): array {
        return [
            'whitespace and line comments' => [
                "var a = 1 ;\n// comment\nvar b = 'x // y';",
                "var a=1;var b='x // y';",
            ],
            'flagged comments are kept' => [
                "/*! license */\nfunction f ( a , b ) {\n  return a + + b ;\n}",
                "/*! license */\nfunction f(a,b){return a+ +b;}",
            ],
            'block comments and unary minus' => [
                'var s = "say \"hi\"";  /* block */ x = a - -b;',
                'var s="say \"hi\"";x=a- -b;',
            ],
            'regex literal' => [
                'var r = /ab+c\/d/gi.test( s ) ;',
                'var r=/ab+c\/d/gi.test(s);',
            ],
            'template literal' => [
                'let t = `a ${ b + 1 }  c`;',
                'let t=`a ${ b + 1 }  c`;',
            ],
            'newline before ++ keeps ASI' => [
                "a = b\n++c",
                "a=b\n++c",
            ],
            'newline after return keeps ASI' => [
                "function g(){ return\n 1 }",
                "function g(){return\n1}",
            ],
        ];
    }

    public function testCombineJoinsWithSemicolons(): void {
        $dir = $this->tmpDir('js-combine');
        $this->writeFile("{$dir}/a.js", 'var a = 1');
        $this->writeFile("{$dir}/b.js", 'var b = 2');

        $combined = File::make("{$dir}/a.js")->combine(['a.js', 'b.js']);

        $this->assertSame("var a = 1;\nvar b = 2", $combined->getRaw());
        $this->assertStringEndsWith('.combined.js', $combined->getPathname());
    }
}
