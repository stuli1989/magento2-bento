<?php

if (!class_exists('Zend_Db_Expr', false)) {
    class Zend_Db_Expr
    {
        private string $expression;

        public function __construct(string $expression)
        {
            $this->expression = $expression;
        }

        public function __toString(): string
        {
            return $this->expression;
        }
    }
}
