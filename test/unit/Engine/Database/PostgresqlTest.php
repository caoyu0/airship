<?php
use \Airship\Engine\Database;
use \Airship\Alerts\Database\DBException;

/**
 * @backupGlobals disabled
 */
class PostgresqlTest extends PHPUnit_Framework_TestCase
{
    const DRIVER = 'pgsql';

    public function getConfig(bool $asString = false)
    {
        if ($asString) {
            return [
                self::DRIVER . ':host=localhost;dbname=phpunit',
                'phpunit',
                'phpunit'
            ];
        }
        return [
            'driver' => self::DRIVER,
            'host' => 'localhost',
            'username' => 'phpunit',
            'password' => 'phpunit',
            'database' => 'phpunit'
        ];
    }

    public function testConstruct()
    {
        $config = $this->getConfig(true);
        $pdo = new \PDO(...$config);
        $db = new Database($pdo, self::DRIVER);
        $this->assertTrue($db instanceof Database);
        $this->assertTrue($db->getDriver() === self::DRIVER);
    }

    public function testFactory()
    {
        $db = Database::factory($this->getConfig());
        $this->assertTrue($db instanceof Database);
    }

    public function testShortQueries()
    {
        $db = Database::factory($this->getConfig());
        $this->assertTrue($db instanceof Database);

        $db->beginTransaction();
        $db->insert(
            'test_values', [
                'name' => 'abc',
                'foo' => true
            ]
        );

        $db->insert(
            'test_values', [
                'name' => 'def',
                'foo' => false
            ]
        );

        $db->insert(
            'test_values', [
                'name' => 'ghijklmnopqrstuvwxyz',
                'foo' => true
            ]
        );

        $row = $db->row('SELECT * FROM test_values WHERE NOT foo');
        $this->assertTrue(\is_array($row));
        $name = $row['name'];
        $db->rollBack();

        $db->beginTransaction();
        $db->insert(
            'test_values', [
                'name' => 'abcdef',
                'foo' => true
            ]
        );

        $db->insert(
            'test_values', [
                'name' => 'GHI',
                'foo' => false
            ]
        );

        $db->insert(
            'test_values', [
                'name' => 'jklmnopqrstuvwxyz',
                'foo' => true
            ]
        );
        $rows = $db->run('SELECT * FROM test_values WHERE NOT foo');
        $this->assertTrue(\count($rows) === 1);

        $count = $db->cell('SELECT count(*) FROM test_values WHERE name = ?', 'GHI');
        $this->assertEquals(\count($rows), $count);

        $count = $db->cell('SELECT count(*) FROM test_values WHERE name = ?', 'def');
        $this->assertNotEquals(\count($rows), $count);

        $db->commit();
    }

    public function testErrors()
    {
        $db = Database::factory($this->getConfig());

        try {
            $db->insert('test_values', [
                'name' => ['test']
            ]);
            $this->fail('Expected a failure here');
        } catch (TypeError $e) {
            // OK
        }

        try {
            $db->update('test_values', [
                'name' => 'test'
            ], [
                'rowid' => [1]
            ]);
            $this->fail('Expected a failure here');
        } catch (TypeError $e) {
            // OK
        }
    }

    public function testZCleanup()
    {
        $db = Database::factory($this->getConfig());
        $db->safeQuery('DELETE FROM test_values');
    }
}