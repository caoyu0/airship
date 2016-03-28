<?php
declare(strict_types=1);
namespace Airship\Hangar\Commands;

use \Airship\Hangar\Command;
use \ParagonIE\Halite\{
    Asymmetric\SignaturePublicKey,
    Asymmetric\SignatureSecretKey,
    File,
    KeyFactory,
    SignatureKeyPair
};

class Sign extends Command
{
    public $essential = true;
    public $display = 7;
    public $name = 'Sign';
    public $description = 'Cryptographically sign an update file.';
    protected $history = null;

    /**
     * Execute the start command, which will start a new hangar session.
     *
     * @param array $args
     * @return bool
     */
    public function fire(array $args = []): bool
    {
        $file = $this->selectFile($args[0] ?? null);
        if (!isset($this->config['salt']) && \count($args) < 2) {
            throw new \Error('No salt configured or passed');
        }
        $salt = $args[1] ?? \Sodium\hex2bin($this->config['salt']);

        echo 'Generating a signature for: ', $file, "\n";
        $password = $this->silentPrompt('Enter password: ');

        $sign_kp = KeyFactory::deriveSignatureKeyPair($password, $salt);
        if (!($sign_kp instanceof SignatureKeyPair)) {
            throw new \Error('Error during key derivation');
        }

        $signature = File::sign(
            $file,
            $sign_kp->getSecretKey()
        );
        if (isset($this->history)) {
            $this->config['build_history']['signed'] = true;
        }
        \file_put_contents($file.'.sig', $signature);
        return true;
    }

    /**
     * Select which file to sign
     *
     * @param mixed $filename
     * @return string
     */
    protected function selectFile($filename = null): string
    {
        if (!empty($filename)) {
            // Did we get passed an absolute path?
            if ($filename[0] === '/') {
                if (!\file_exists($filename)) {
                    throw new \Error('File not found: ' . $filename);
                }
                return $filename;
            }

            $dir = \getcwd();
            $path = \realpath($dir . DIRECTORY_SEPARATOR . $filename);
            if (!\file_exists($path)) {
                // Ok, try in the Airship config directory then?
                $path = \realpath(AIRSHIP_LOCAL_CONFIG . DIRECTORY_SEPARATOR . $filename);
                if (!\file_exists($path)) {
                    throw new \Error('File not found: ' . $filename);
                }
            }
            return $path;
        }

        // Let's grab it from our build history then, eh?
        $files = $this->config['build_history'] ?? [];
        if (empty($files)) {
            throw new \Error('No recent builds. Try specifying ');
        }
        $keys = \array_keys($files);
        $this->history = \array_pop($keys);
        $last = $files[$this->history];
        return $last['path'];
    }
}
