<?php

namespace ST_system\Tests\Unit;

use ST_system\CensorText;
use ST_system\Tests\TestCase;

final class CensorTextTest extends TestCase {

    private function censor(bool $normalize = true): CensorText {
        return new CensorText([
            'mat'     => ['слово'],
            'insults' => ['дурак', 'идиот'],
        ], $normalize);
    }

    public function testCheckPerGroup(): void {
        $this->assertSame(['mat' => false, 'insults' => true], $this->censor()->check('ты дурак!'));
        $this->assertSame(['mat' => false, 'insults' => false], $this->censor()->check('всё хорошо'));
    }

    public function testRootMatchesWordForms(): void {
        $this->assertTrue($this->censor()->checkAll('какие дураки'));
        $this->assertTrue($this->censor()->checkAll('ИДИОТСКИЙ'));
        $this->assertFalse($this->censor()->checkAll('придурок'));
    }

    public function testNormalizationCatchesLeetspeak(): void {
        $this->assertTrue($this->censor()->checkAll('ид1от'));
        $this->assertTrue($this->censor()->checkAll('д.у.р.а.к'));
        $this->assertFalse($this->censor(false)->checkAll('ид1от'));
    }

    public function testNormalizationMapAdd(): void {
        $censor = $this->censor();
        $censor->normalization_map_add(['y' => 'у']);

        $this->assertTrue($censor->checkAll('дyрак'));
    }

    public function testCountListsOnlyMatchedGroups(): void {
        $this->assertSame(['insults' => 3], $this->censor()->count('дурак дурак идиот'));
        $this->assertSame([], $this->censor()->count('чисто'));
        $this->assertSame(3, $this->censor()->countAll('дурак дурак идиот'));
    }

    public function testCensorReturnsNormalizedText(): void {
        $this->assertSame('ты *****, а он ******', $this->censor(false)->censor('Ты ДУРАК, а он идиоты'));
        $this->assertSame('ты ***** а он *****', $this->censor()->censor('Ты ДУРАК, а он идиот'));
        // `!` нормализуется в `и` и становится частью слова.
        $this->assertSame('ты ********', $this->censor()->censor('Ты ДУРАК!!!'));
    }

    public function testResultsAreCachedPerText(): void {
        $censor = $this->censor();

        $this->assertSame($censor->check('дурак'), $censor->check('дурак'));
        $this->assertSame($censor->censor('дурак'), $censor->censor('дурак'));
    }
}
