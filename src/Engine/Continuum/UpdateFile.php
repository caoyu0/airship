<?php
declare(strict_types=1);
namespace Airship\Engine\Continuum;

class UpdateFile
{
    protected $path;
    protected $size;
    protected $hash;
    protected $version;

    public function __construct(array $data)
    {
        $this->path = $data['path'];
        $this->version = $data['version'];
        $this->size = $data['size'] ?? \filesize($data['path']);
        $this->hash = $data['hash'] ?? \Sodium\bin2hex(
                \Sodium\crypto_generichash(
                    \file_get_contents($data['path'])
                )
            );
    }

    /**
     * Get the hex-encoded hash of the file contents
     *
     * @return string
     */
    public function getHash(): string
    {
        return $this->hash;
    }

    /**
     * Get the name of the file
     *
     * @return string
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Get the size of the file
     *
     * @return int
     */
    public function getSize(): int
    {
        return $this->size;
    }

    /**
     * Does the given hash match the file?
     *
     * @param string $hash
     * @return bool
     */
    public function hashMatches(string $hash): bool
    {
        return \hash_equals($this->hash, $hash);
    }

    public function getVersion(): string
    {
        return $this->version;
    }
}
