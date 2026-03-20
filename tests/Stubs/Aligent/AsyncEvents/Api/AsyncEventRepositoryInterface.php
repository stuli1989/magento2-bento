<?php

declare(strict_types=1);

namespace Aligent\AsyncEvents\Api;

use Aligent\AsyncEvents\Api\Data\AsyncEventSearchResultInterface;

interface AsyncEventRepositoryInterface
{
    public function getList($searchCriteria);
    public function save($asyncEvent, bool $withResource = true);
}
