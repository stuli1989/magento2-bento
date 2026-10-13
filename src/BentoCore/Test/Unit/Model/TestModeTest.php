<?php

declare(strict_types=1);

namespace ArtLounge\BentoCore\Test\Unit\Model;

use ArtLounge\BentoCore\Model\TestMode;
use PHPUnit\Framework\TestCase;

class TestModeTest extends TestCase
{
    public function testEmptyListIsInactiveAndAllowsEverything(): void
    {
        $mode = new TestMode([]);
        $this->assertFalse($mode->isActive());
        $this->assertTrue($mode->allows('anyone@example.com'));
    }

    public function testPlusAddressPatternMatches(): void
    {
        $mode = new TestMode(['*+bentotest*@*']);
        $this->assertTrue($mode->isActive());
        $this->assertTrue($mode->allows('kshitij+bentotest1@gmail.com'));
        $this->assertTrue($mode->allows('KSHITIJ+BentoTest-cod@Gmail.com'));
        $this->assertFalse($mode->allows('kshitij@gmail.com'));
        $this->assertFalse($mode->allows('bentotest@gmail.com'));
    }

    public function testMultiplePatterns(): void
    {
        $mode = new TestMode(['*+bentotest*@*', 'qa@artlounge.in']);
        $this->assertTrue($mode->allows('qa@artlounge.in'));
        $this->assertFalse($mode->allows('qa2@artlounge.in'));
    }

    public function testEmptyEmailNeverAllowedWhenActive(): void
    {
        $mode = new TestMode(['*']);
        $this->assertFalse($mode->allows(''));
    }

    public function testFromStringSplitsOnCommasAndNewlines(): void
    {
        $mode = TestMode::fromString(" *+bentotest*@* ,\nqa@artlounge.in\r\n\n");
        $this->assertSame(['*+bentotest*@*', 'qa@artlounge.in'], $mode->getPatterns());
    }

    public function testRegexMetacharactersAreLiteral(): void
    {
        $mode = new TestMode(['a.b@x.com']);
        $this->assertFalse($mode->allows('axb@x.com'));
        $this->assertTrue($mode->allows('a.b@x.com'));
    }
}
