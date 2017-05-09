<?php

/*
 * This file is part of the Carbon package.
 *
 * (c) Brian Nesbitt <brian@nesbot.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Carbon\Exceptions;

use Exception;
use InvalidArgumentException;

class InvalidDateException extends InvalidArgumentException
{
    /*
     * 无效字段
     *
     * @var string
     */
    private $field;

    /*
     * 无效值
     *
     * @var mixed
     */
    private $value;

    /*
     * 构造函数
     *
     * @param string          $field
     * @param mixed           $value
     * @param int             $code
     * @param \Exception|null $previous
     */
    public function __construct($field, $value, $code = 0, Exception $previous = null)
    {
        $this->field = $field;
        $this->value = $value;
        parent::__construct($field.' : '.$value.' is not a valid value.', $code, $previous);
    }

    /*
     * 获取无效字段
     *
     * @return string
     */
    public function getField()
    {
        return $this->field;
    }

    /*
     * 获取无效值
     *
     * @return mixed
     */
    public function getValue()
    {
        return $this->value;
    }
}
