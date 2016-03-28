<?php
declare(strict_types=1);
namespace Airship\Engine\Cache;

use \Airship\Alerts\InvalidType;
use \Airship\Engine\{
    Contract\CacheInterface,
    Security\Util,
    State
};
use \ParagonIE\Halite\Key;

class File implements CacheInterface
{
    const HASH_SIZE = 32;
    const PERMS = 0755;

    protected $baseDir = '';

    /**
     * File constructor.
     *
     * @param string $baseDir The base directory
     */
    public function __construct(string $baseDir)
    {
        $this->baseDir = $baseDir;
    }

    /**
     * Delete a cache entry
     *
     * @param string $key
     * @return bool|null
     */
    public function delete(string $key)
    {
        $path = $this->getRelativePath($key);
        if (\file_exists($path)) {
            return \unlink($path);
        }
    }

    /**
     * Get a cache entry
     *
     * @param string $key
     * @return null|mixed
     */
    public function get(string $key)
    {
        $path = $this->getRelativePath($key);
        if (@\is_readable($path)) {
            return \file_get_contents($path);
        }
        // NULL means nothing was found
        return null;
    }

    /**
     * Set a cache entry
     *
     * @param string $key
     * @param $value
     * @return mixed
     */
    public function set(string $key, $value): bool
    {
        $path = $this->getRelativePath($key);

        // Let's make sure both directories exist
        $dirs = self::getRelativeHash($this->baseDir . DIRECTORY_SEPARATOR . $key);
        if (!\is_dir($this->baseDir . DIRECTORY_SEPARATOR . $dirs[0])) {
            \mkdir($this->baseDir . DIRECTORY_SEPARATOR . $dirs[0], self::PERMS);
        }
        if (!\is_dir($this->baseDir . DIRECTORY_SEPARATOR . $dirs[0] . DIRECTORY_SEPARATOR . $dirs[1])) {
            \mkdir($this->baseDir . DIRECTORY_SEPARATOR . $dirs[0] . DIRECTORY_SEPARATOR . $dirs[1], self::PERMS);
        }

        // Now let's store our data in the file
        return \file_put_contents($path, $value) !== false;
    }

    /**
     * Get a relative BLAKE2b hash of an input. Formatted as two lookup
     * directories followed by a cache entry. 'hh/hh/hhhhhhhh...'
     *
     * @param string $preHash The cache identifier (will be hashed)
     * @param bool $asString Return a string?
     * @return string|array
     * @throws InvalidType
     */
    public static function getRelativeHash(string $preHash, bool $asString = false)
    {
        $state = State::instance();
        $cacheKey = $state->keyring['cache.hash_key'];

        if (!($cacheKey instanceof Key)) {
            throw new InvalidType(
                \trk('errors.type.wrong_class', '\ParagonIE\Halite\Key')
            );
        }

        // We use a keyed hash, with a distinct key per Airship deployment to
        // make collisions unlikely,
        $hash = \Sodium\crypto_generichash(
            $preHash,
            $cacheKey->getRawKeyMaterial(),
            self::HASH_SIZE
        );

        $relHash = [
            \Sodium\bin2hex($hash[0]),
            \Sodium\bin2hex($hash[1]),
            \Sodium\bin2hex(Util::subString($hash, 2)),
        ];
        if ($asString) {
            return \implode(DIRECTORY_SEPARATOR, $relHash);
        }
        return $relHash;
    }

    /**
     * Get the relative path
     *
     * @param string $key
     * @return string
     */
    protected function getRelativePath(string $key): string
    {
        return $this->baseDir .
            DIRECTORY_SEPARATOR .
            self::getRelativeHash(
                $this->baseDir . DIRECTORY_SEPARATOR . $key,
                true
            );
    }
}
