<?php
/**
 * @author     Jan Schneider <jan@horde.org>
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @category   Horde
 * @package    Util
 * @subpackage UnitTests
 */
namespace Horde\Util\Test;
use PHPUnit\Framework\TestCase;
use Horde_Array;

class ArrayTest extends TestCase
{
    public function setUp(): void
    {
        $this->array = array(
            array('name' => 'foo', 'desc' => 'foo long desc'),
            array('name' => 'aaaa', 'desc' => 'aaa foo long desc'),
            array('name' => 'baby', 'desc' => 'The test data was boring'),
            array('name' => 'zebra', 'desc' => 'Striped armadillos'),
            array('name' => 'umbrage', 'desc' => 'resentment'),
        );
    }

    public function testArraySort()
    {
        Horde_Array::arraySort($this->array);
        $this->assertEquals(
            array(
                1 => array('name' => 'aaaa', 'desc' => 'aaa foo long desc'),
                2 => array('name' => 'baby', 'desc' => 'The test data was boring'),
                0 => array('name' => 'foo', 'desc' => 'foo long desc'),
                4 => array('name' => 'umbrage', 'desc' => 'resentment'),
                3 => array('name' => 'zebra', 'desc' => 'Striped armadillos'),
            ),
            $this->array
        );
    }

    public function testArraySortKey()
    {
        Horde_Array::arraySort($this->array, 'desc');
        $this->assertEquals(
            array(
                1 => array('name' => 'aaaa', 'desc' => 'aaa foo long desc'),
                0 => array('name' => 'foo', 'desc' => 'foo long desc'),
                4 => array('name' => 'umbrage', 'desc' => 'resentment'),
                3 => array('name' => 'zebra', 'desc' => 'Striped armadillos'),
                2 => array('name' => 'baby', 'desc' => 'The test data was boring'),
            ),
            $this->array
        );
    }
}
