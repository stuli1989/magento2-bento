<?php
/**
 * Reads the JSON body and HTTP code out of a Magento Result\Json without rendering it.
 * Result\Json has no getters; its state lives in protected $json / $httpResponseCode.
 * Unknown result types → ['code' => 200, 'data' => null] (callers then do nothing).
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Razorpay;

class ResultReader
{
    /** @return array{code:int, data:?array} */
    public function read(mixed $result): array
    {
        if (!is_object($result)) {
            return ['code' => 200, 'data' => null];
        }
        $json = $this->property($result, 'json');
        $code = $this->property($result, 'httpResponseCode');
        $data = is_string($json) ? json_decode($json, true) : null;

        return [
            'code' => $code ? (int)$code : 200,
            'data' => is_array($data) ? $data : null,
        ];
    }

    private function property(object $object, string $name): mixed
    {
        $class = new \ReflectionClass($object);
        do {
            if ($class->hasProperty($name)) {
                $prop = $class->getProperty($name);
                $prop->setAccessible(true);
                return $prop->isInitialized($object) ? $prop->getValue($object) : null;
            }
            $class = $class->getParentClass();
        } while ($class);

        return null;
    }
}
