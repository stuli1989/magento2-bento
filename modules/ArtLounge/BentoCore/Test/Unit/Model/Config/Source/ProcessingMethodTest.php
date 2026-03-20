<?php
/**
 * Processing Method Source Model Unit Test
 */

declare(strict_types=1);

namespace ArtLounge\BentoCore\Test\Unit\Model\Config\Source;

use ArtLounge\BentoCore\Model\Config\Source\ProcessingMethod;
use PHPUnit\Framework\TestCase;

class ProcessingMethodTest extends TestCase
{
    public function testToOptionArrayReturnsOptions(): void
    {
        $model = new ProcessingMethod();

        $options = $model->toOptionArray();

        $this->assertCount(2, $options);
        $this->assertSame('cron', $options[0]['value']);
        $this->assertSame('queue', $options[1]['value']);
        $this->assertSame('Cron (Recommended)', (string)$options[0]['label']);
    }
}
