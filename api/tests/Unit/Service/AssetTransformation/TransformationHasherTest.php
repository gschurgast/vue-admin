<?php

namespace App\Tests\Unit\Service\AssetTransformation;

use App\Entity\AssetTransformation\AssetTransformation;
use App\Entity\AssetTransformation\TransformationStep;
use App\Enum\StepType;
use App\Service\AssetTransformation\TransformationHasher;
use PHPUnit\Framework\TestCase;

final class TransformationHasherTest extends TestCase
{
    private TransformationHasher $hasher;

    protected function setUp(): void
    {
        $this->hasher = new TransformationHasher();
    }

    public function testReturns40CharSha1(): void
    {
        $t = $this->buildTransformation([
            ['type' => 'resize', 'params' => ['width' => 800], 'position' => 0],
        ]);
        $hash = $this->hasher->compute($t);
        self::assertSame(40, strlen($hash));
        self::assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $hash);
    }

    public function testDeterministicAcrossRuns(): void
    {
        $t = $this->buildTransformation([
            ['type' => 'resize', 'params' => ['width' => 800, 'height' => 600], 'position' => 0],
        ]);
        $a = $this->hasher->compute($t);
        $b = $this->hasher->compute($t);
        self::assertSame($a, $b);
    }

    public function testParamKeyOrderIndependent(): void
    {
        $t1 = $this->buildTransformation([
            ['type' => 'resize', 'params' => ['width' => 800, 'height' => 600, 'mode' => 'fit'], 'position' => 0],
        ]);
        $t2 = $this->buildTransformation([
            ['type' => 'resize', 'params' => ['mode' => 'fit', 'height' => 600, 'width' => 800], 'position' => 0],
        ]);
        self::assertSame($this->hasher->compute($t1), $this->hasher->compute($t2));
    }

    public function testNullParamsAreDropped(): void
    {
        $t1 = $this->buildTransformation([
            ['type' => 'rotate', 'params' => ['angle' => 90, 'background' => null], 'position' => 0],
        ]);
        $t2 = $this->buildTransformation([
            ['type' => 'rotate', 'params' => ['angle' => 90], 'position' => 0],
        ]);
        self::assertSame($this->hasher->compute($t1), $this->hasher->compute($t2));
    }

    public function testStepPositionOrderMatters(): void
    {
        $t1 = $this->buildTransformation([
            ['type' => 'resize', 'params' => ['width' => 800], 'position' => 0],
            ['type' => 'format_convert', 'params' => ['format' => 'webp'], 'position' => 1],
        ]);
        $t2 = $this->buildTransformation([
            ['type' => 'resize', 'params' => ['width' => 800], 'position' => 1],
            ['type' => 'format_convert', 'params' => ['format' => 'webp'], 'position' => 0],
        ]);
        self::assertNotSame($this->hasher->compute($t1), $this->hasher->compute($t2));
    }

    public function testEmptyStepsListIsStable(): void
    {
        $t = new AssetTransformation();
        $hash = $this->hasher->compute($t);
        self::assertSame(40, strlen($hash));
        self::assertSame(sha1('[]'), $hash);
    }

    public function testGoldenHashFixture(): void
    {
        $t = $this->buildTransformation([
            ['type' => 'resize',         'params' => ['height' => 600, 'mode' => 'fit', 'width' => 800], 'position' => 0],
            ['type' => 'format_convert', 'params' => ['format' => 'webp', 'quality' => 85],              'position' => 1],
        ]);
        // Golden hash v1.0 — frozen 2026-05-27.
        // NEVER change without coordinated cache invalidation across all environments.
        $expectedGolden = '0b341db4763bc1a68b6c6cfce6cce866594de409';
        self::assertSame($expectedGolden, $this->hasher->compute($t));
    }

    /**
     * @param list<array{type:string,params:array,position:int}> $stepsData
     */
    private function buildTransformation(array $stepsData): AssetTransformation
    {
        $t = new AssetTransformation();
        foreach ($stepsData as $d) {
            $s = new TransformationStep();
            $s->setType(StepType::from($d['type']));
            $s->setParams($d['params']);
            $s->setPosition($d['position']);
            $t->addStep($s);
        }
        return $t;
    }
}
