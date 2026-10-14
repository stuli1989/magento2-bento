<?php

declare(strict_types=1);

namespace Magento\Framework\DB;

class Select
{
    public function from($name, $cols = '*'): self { return $this; }
    public function where($cond, $value = null): self { return $this; }
    public function limit($count, $offset = null): self { return $this; }
    public function group($spec): self { return $this; }
    public function join($name, $cond, $cols = '*'): self { return $this; }
    public function joinLeft($name, $cond, $cols = '*'): self { return $this; }
    public function order($spec): self { return $this; }
}
