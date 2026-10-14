<?php

declare(strict_types=1);

namespace Magento\Framework\DB\Adapter;

interface AdapterInterface
{
    public function insertOnDuplicate($table, array $data, array $fields = []);
    public function update($table, array $data, $where = []);
    public function select();
    public function fetchOne($select);
    public function fetchAll($select);
    public function fetchPairs($select, $bind = []);
    public function fetchRow($select, $bind = []);
    public function fetchCol($select, $bind = []);
    public function delete($table, $where = []);
}
