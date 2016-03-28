<?php
declare(strict_types=1);
namespace Airship\Hangar\Commands;

use \Airship\Hangar\SessionCommand;
use \ParagonIE\ConstantTime\Base64UrlSafe;

class Assemble extends SessionCommand
{
    public $essential = true;
    public $display = 6;
    public $name = 'Assemble';
    public $description = 'Build a PHP Archive with an update package.';
    public $pharname; // File name of the output PHP archive
    public $pharAlias = 'airship-update.phar';

    /**
     * Execute the assemble command
     *
     * @param array $args
     * @return mixed
     */
    public function fire(array $args = []): bool
    {
        $this->getSession();

        // Create a workspace directory:
        $workspace = $this->createWorkspace();
        $this->pharStub = $workspace . DIRECTORY_SEPARATOR . 'index.php';
        $this->buildPhar($workspace, $args);

        if (!\in_array('build_history', $this->config)) {
            $this->config['build_history'] = [];
        }
        $dt = new \DateTime('now');
        $this->config['build_history'][] = [
            'name' => $this->pharname,
            'path' => AIRSHIP_LOCAL_CONFIG . DIRECTORY_SEPARATOR . $this->pharname,
            'date' => $dt->format('Y-m-d H:i:s'),
            'signed' => false
        ];

        // Unless we passed --no-flush, we nuke this first
        if (!\in_array('--no-flush', $args)) {
            $this->session = [];
        }
        return true;
    }

    /**
     * Add an autorun entry.
     *
     * @param array $run
     * @param string $workspace
     * @string file
     * @return bool
     * @throws \Error
     */
    protected function addAutorun(array $run, string $workspace, string $file): bool
    {
        static $db_tpl = null;
        if ($db_tpl === null) {
            $db_tpl = \file_get_contents(
                \dirname(HANGAR_ROOT) . DIRECTORY_SEPARATOR . 'res' . DIRECTORY_SEPARATOR . 'index.php.tmp'
            );
        }

        $hash = \Sodium\bin2hex(
            \Sodium\crypto_generichash($file)
        );

        switch ($run['type']) {
            case 'php':
                \file_put_contents(
                    $workspace . DIRECTORY_SEPARATOR . 'autorun' . DIRECTORY_SEPARATOR . $hash . '.php',
                    Base64::decode($run['data'])
                );
                \file_put_contents(
                    $workspace . DIRECTORY_SEPARATOR . 'autorun.php',
                    'require_once __DIR__ . DIRECTORY_SEPARATOR . "autorun" . DIRECTORY_SEPARATOR . "'. $hash . '.php";' . "\n",
                    FILE_APPEND
                );
                return true;

            case 'mysql':
            case 'pgsql':
                $exec = \str_replace([
                        '@_QUERY_@',
                        '@_DRIVER_@'
                    ], [
                        \str_replace('"', '\\"', Base64::decode($run['data'])),
                        $run['type']
                    ], $db_tpl);

                // Save the template file:
                \file_put_contents(
                    $workspace . DIRECTORY_SEPARATOR . 'autorun' . DIRECTORY_SEPARATOR . $hash . '.php',
                    $exec
                );

                // Add the autorun script to the autorun list:
                \file_put_contents(
                    $workspace . DIRECTORY_SEPARATOR . 'autorun.php',
                    'require_once __DIR__ . DIRECTORY_SEPARATOR . "autorun" . DIRECTORY_SEPARATOR . $hash . ".php";' . "\n",
                    FILE_APPEND
                );
                return true;

            default:
                throw new \Error(
                    'Unknown type: '.$run['type']
                );
        }
    }

    /**
     * Build the Phar
     *
     * @param string $workspace
     * @param array $args
     * @return bool
     */
    protected function buildPhar(string $workspace, array $args = []): bool
    {
        $this->setupFiles($workspace, $args);

        // We don't need this to be random:
        $this->pharname = 'airship-'.\date('YmdHis').'.phar';

        $phar = new \Phar(
            AIRSHIP_LOCAL_CONFIG . DIRECTORY_SEPARATOR . $this->pharname,
            \FilesystemIterator::CURRENT_AS_FILEINFO | \FilesystemIterator::KEY_AS_FILENAME,
            $this->pharAlias
        );
        $phar->buildFromDirectory($workspace);
        $phar->setMetadata($this->getMetadata());

        return $this->cleanupWorkspace($workspace);
    }

    /**
     * Copy a file from one directory to another, ensuring that the
     * destination directory exists.
     *
     * @param string $from
     * @param string $to
     * @param string $filename
     * @return bool
     */
    protected function copyFile(string $from, string $to, string $filename): bool
    {
        $acc = '';
        $exploded = \explode(
            DIRECTORY_SEPARATOR,
            \trim(\dirname($to . DIRECTORY_SEPARATOR . $filename), DIRECTORY_SEPARATOR)
        );
        foreach ($exploded as $piece) {
            $acc .= DIRECTORY_SEPARATOR . $piece;
            if (!\is_dir($acc)) {
                \mkdir($acc, 0755);
            }
        }

        return \copy(
            $from . DIRECTORY_SEPARATOR . $filename,
            $to . DIRECTORY_SEPARATOR . $filename
        );
    }

    /**
     * Create a random workspace directory
     *
     * @return string
     * @throws \Error
     */
    protected function createWorkspace(): string
    {
        do {
            $dirname = Base64UrlSafe::encode(\random_bytes(18));
        } while(\is_dir(AIRSHIP_LOCAL_CONFIG . DIRECTORY_SEPARATOR . $dirname));
        if (!\mkdir(AIRSHIP_LOCAL_CONFIG . DIRECTORY_SEPARATOR . $dirname, 0700)) {
            throw new \Error(
                'Could not create workspace directory: ' .
                AIRSHIP_LOCAL_CONFIG . DIRECTORY_SEPARATOR . $dirname
            );
        }
        return AIRSHIP_LOCAL_CONFIG . DIRECTORY_SEPARATOR . $dirname;
    }

    /**
     * Recursively delete an entire directory
     *
     * @param string $dir
     * @return bool
     */
    protected function cleanupWorkspace(string $dir): bool
    {
        $files = \array_diff(
            \scandir($dir),
            ['.', '..']
        );

        foreach ($files as $file) {
            if (\is_dir($dir . DIRECTORY_SEPARATOR . $file)) {
                $this->cleanupWorkspace($dir . DIRECTORY_SEPARATOR . $file);
            } else {
                \unlink($dir . DIRECTORY_SEPARATOR . $file);
            }
        }
        return \rmdir($dir);
    }

    /**
     * @return string
     */
    protected function getMetadata(): string
    {
        return \json_encode($this->metadata);
    }

    /**
     * Place all the files in a workspace directory to prepare for Phar assembly.
     *
     * @param string $workspace
     * @param array $args
     */
    protected function setupFiles(string $workspace, array $args = [])
    {
        $from = $this->session['dir'];
        if (\in_array('metadata', $this->session)) {
            $this->metadata = $this->session['metadata'];
        }

        \copy(
            \dirname(HANGAR_ROOT) . DIRECTORY_SEPARATOR . 'res' . DIRECTORY_SEPARATOR . 'index.php.tmp',
            $this->pharStub
        );

        // Let's make sure our autorun directory exists
        \mkdir($workspace . DIRECTORY_SEPARATOR . 'autorun', 0755);
        \file_put_contents(
            $workspace. DIRECTORY_SEPARATOR . 'autorun.php',
            '<?php' . "\n" . 'declare(strict_types=1);' . "\n"
        );
        if (\array_key_exists('add', $this->session)) {
            foreach ($this->session['add'] as $file) {
                $this->copyFile($from, $workspace, $file);
                $this->metadata['files'][] = $file;
            }
        }
        if (\array_key_exists('autorun', $this->session)) {
            foreach ($this->session['autorun'] as $f => $run) {
                $this->addAutorun($run, $workspace, $f);
            }
        }
    }
}
