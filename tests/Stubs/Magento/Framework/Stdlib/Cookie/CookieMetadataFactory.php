<?php

declare(strict_types=1);

namespace Magento\Framework\Stdlib\Cookie;

class CookieMetadataFactory
{
    public function createCookieMetadata($metadata = null) { return new PublicCookieMetadata(); }
}
