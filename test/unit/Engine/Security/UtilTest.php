<?php
use Airship\Engine\Security\Util;

/**
 * @backupGlobals disabled
 */
class UtilTest extends PHPUnit_Framework_TestCase
{
    public function testRandomString()
    {
        $sample = [
            Util::randomString(),
            Util::randomString()
        ];
        
        $this->assertFalse($sample[0] === $sample[1]);
    }
}
