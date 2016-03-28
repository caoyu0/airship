<?php
declare(strict_types=1);
namespace Airship\Installer;

use \Airship\Engine\{
    Database,
    State
};
use \Airship\Engine\Security\CSRF;
use \ParagonIE\Halite\Password;
use \GuzzleHttp\Client;
use \ParagonIE\ConstantTime\Base64;

class Install
{
    const GROUP_ADMIN = 7;
    
    protected $db;
    protected $autoSave = true;
    protected $twig;
    protected $data;
    
    public function __construct(\Twig_Environment $twig, array $data = [])
    {
        $this->twig = $twig;
        $this->data = $data;
        $this->csrf = new CSRF();
        
        // We do this to prevent someone from coming along and reading your
        // half-finished configuration settings (e.g. database passwords):
        if (empty($this->data['step'])) {
            $this->data['step'] = 1;
            $this->data['token'] = Base64::encode(
                \random_bytes(33)
            );
            \setcookie('installer', $this->data['token'], \time() + 8640000, '/');
        } elseif (empty($_COOKIE['installer'])) {
            echo 'No installer authorization token found.', "\n";
            exit(255);
        } elseif (!\hash_equals($this->data['token'], $_COOKIE['installer'])) {
            echo 'Invalid installer authorization token.', "\n";
            exit(255);
        }
    }
    
    public function __destruct()
    {
        if ($this->autoSave) {
            \file_put_contents(
                ROOT.'/tmp/installing.json',
                \json_encode($this->data, JSON_PRETTY_PRINT)
            );
        }
    }
    
    /**
     * This is the landing point for the current step in the installation 
     * process.
     */
    public function currentStep()
    {
        $post = $this->post();
        switch ($this->data['step']) {
            case 1:
                if (!empty($post)) {
                    return $this->processDatabase($post);
                }
                $this->data['drivers'] = $this->enumerateDrivers();
                return $this->display('database.twig');
            case 2:
                if (!empty($post)) {
                    return $this->processAdminAccount($post);
                }
                return $this->display('admin_account.twig');
            case 3:
                if (!empty($post)) {
                    return $this->processCabins($post);
                }
                return $this->display('cabins.twig');
            case 4:
                $this->testForTor();
                if (!empty($post)) {
                    return $this->processConfig($post);
                }
                return $this->display('config.twig');
            case 5:
                if (!empty($post)) {
                    return $this->finalize($post);
                }
                return $this->display('review.twig');
        }
        exit;
    }
    
    /**
     * Render a template
     * 
     * @param string $template
     * @return bool
     */
    protected function display(string $template)
    {
        $data = $this->data;
        $data['POST'] = $_POST;
        echo $this->twig->render($template, $data);
        return true;
    }
    
    /**
     * This method is where we will detect and enumerate all of our supported
     * drivers.
     * 
     * @return array
     */
    protected function enumerateDrivers() : array
    {
        $drivers = [];
        if (\extension_loaded('pgsql') && \extension_loaded('pdo_pgsql')) {
            $drivers['pgsql'] = 'PostgreSQL';
        }
        if (\extension_loaded('mysql') && \extension_loaded('pdo_mysql')) {
            $drivers['mysql'] = 'MySQL';
        }
        
        // While our Database class supports these, our core architecture
        // does not explicitly support them yet. Use at your own risk:
        
        /*
        if (\extension_loaded('sqlite') && \extension_loaded('pdo_sqlite')) {
            $drivers['sqlite'] = 'SQLite';
        }
        */
        return $drivers;
    }
    
    /**
     * Store our database configuration information, then proceed to step 2.
     * 
     * @param array $post
     */
    protected function processDatabase(array $post = [])
    {
        if (empty($post['database'])) {
            return;
        }
        if (empty($post['database'][0]['host'])) {
            $post['database'][0]['host'] = 'localhost';
        }
        $this->data['database'] = $post['database'];
        $this->data['step'] = 2;
        \Airship\redirect('/');
    }
    
    /**
     * Store your admin account credentials.
     * 
     * @param array $post
     */
    protected function processAdminAccount(array $post = [])
    {
        if (empty($post['passphrase'])) {
            return;
        }
        if (empty($post['username'])) {
            $post['username'] = 'captain';
        }
        $state = State::instance();
        
        $this->data['admin'] = [
            'username' => $post['username'],
            'passphrase' => Password::hash(
                $post['passphrase'],
                $state->keyring['auth.password_key']
            )
        ];
        $this->data['step'] = 3;
        \Airship\redirect('/');
    }
    
    /**
     * Store our database configuration information, then proceed to step 2.
     * 
     * @param array $post
     */
    protected function processCabins(array $post = [])
    {
        $this->data['cabins'] = $post['cabin'];
        $this->data['step'] = 4;
        return \Airship\redirect('/');
    }
    
    /**
     * Store our database configuration information, then proceed to step 2.
     * 
     * @param array $post
     */
    protected function processConfig(array $post = [])
    {
        $this->data['config'] = $post['config'];
        
        $this->data['step'] = 5;
        return \Airship\redirect('/');
    }
    
    /**
     * Grab post data, but only if the CSRF token is valid
     * 
     * @param bool $ignoreCSRFToken - Don't validate CSRF tokens
     * 
     * @return array|bool
     */
    protected function post(bool $ignoreCSRFToken = false)
    {
        if (empty($_POST)) {
            return false;
        }
        if ($ignoreCSRFToken) {
            return $_POST;
        }
        if ($this->csrf->check()) {
            return $_POST;
        }
    }
    
    /**
     * Finalize the install process
     * 
     * @param array $post
     */
    protected function finalize(array $post = [])
    {
        $state = State::instance();
        $this->data['admin']['username'] = $post['username'];
        if (!empty($post['passphrase'])) {
            // Password was changed:
            $this->data['admin']['passphrase'] = Password::hash(
                    $post['passphrase'],
                    $state->keyring['auth.password_key']
            );
        }
        $this->data['cabins'] = $post['cabin'];
        $this->data['config'] = $post['config'];
        $this->data['database'] = $post['database'];
        
        $this->finalConfiguration();
        $this->finalDatabaseSetup();
        $this->finalProcessAdminAccount();
        $this->finalShutdown();
    }
    
    /**
     * This will attempt to connect to a Tor Hidden Service, to see if Tor is
     * available for use.
     */
    protected function testForTor()
    {
        $guzzle = new Client();
        try {
            $response = $guzzle->get(
                'http://duskgytldkxiuqc6.onion',
                [
                    'curl' => [
                        CURLOPT_PROXY => 'http://localhost:9050',
                        CURLOPT_PROXYTYPE => CURLPROXY_SOCKS5_HOSTNAME,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_HTTPPROXYTUNNEL => true
                    ]
                ]
            );
            $this->data['tor_installed'] = (
                \stripos((string) $response->getBody(), 'Example rendezvous points page') !== false
            );
        } catch (\RuntimeException $e) {
            $this->data['tor_installed'] = false;
        }
    }
    
    protected function finalConfiguration()
    {
        $twigEnv = \Airship\configWriter(ROOT.'/config/templates');

        \file_put_contents(
            ROOT.'/config/cabins.json',
            $this->finalConfigCabins($twigEnv)
        );
        \file_put_contents(
            ROOT.'/config/databases.json',
            $this->finalConfigDatabases($twigEnv)
        );
        \file_put_contents(
            ROOT.'/config/gears.json',
            '[]'
        );
        \file_put_contents(
            ROOT.'/config/universal.json',
            $this->finalConfigUniversal($twigEnv)
        );
    }
    
    /**
     * Get the data for the cabins.json file
     * 
     * This is in a separate method so it can be unit tested
     * @param \Twig_Environment $twig
     * @return string
     */
    protected function finalConfigCabins(\Twig_Environment $twig): string
    {
        $cabins = [];
        foreach ($this->data['cabins'] as $name => $conf) {
            $cabins[$conf['path']] = [
                'https' => !empty($conf['https']),
                'language' => $conf['lang'],
                'name' => $name
            ];
        }
        return $twig->render('cabins.twig', [
            'cabins' => $cabins
        ]);
    }
    
    /**
     * Get the data for the databases.json file
     * 
     * This is in a separate method so it can be unit tested
     * @param \Twig_Environment $twig
     * @return string
     */
    protected function finalConfigDatabases(\Twig_Environment $twig): string
    {
        $databases = [];
        $databases['default'] = [
            [
                'driver' => $this->data['database'][0]['driver'],
                'host' => $this->data['database'][0]['host'] ?? 'localhost',
                'port' => $this->data['database'][0]['port'] ?? null,
                'database' => $this->data['database'][0]['dbname'],
                'username' => $this->data['database'][0]['username'],
                'password' => $this->data['database'][0]['password'],
                'options' => []
            ]
        ];
        
        $n = \count($this->data['databases']);
        if ($n > 1) {
            for ($i = 1; $i < $n; ++$i) {
                $row = $this->data['databases'][$i];
                
                // By default, we treat these as redundancy databases:
                $g = $row['group'] ?? 'default';
                
                if (empty($databases[$g])) {
                    $databases[$g] = [];
                }
                $databases[$g][] = [
                    'driver' => $this->data['database'][$i]['driver'],
                    'host' => $this->data['database'][$i]['host'] ?? 'localhost',
                    'port' => $this->data['database'][$i]['port'] ?? null,
                    'database' => $this->data['database'][$i]['dbname'],
                    'username' => $this->data['database'][$i]['username'],
                    'password' => $this->data['database'][$i]['password'],
                    'options' => []
                ];
            }
        }
        return $twig->render('databases.twig', [
            'databases' => $databases
        ]);
    }
    
    /**
     * Get the data for the universal.json file
     * 
     * This is in a separate method so it can be unit tested
     * @param \Twig_Environment $twig
     * @return string
     */
    protected function finalConfigUniversal(\Twig_Environment $twig): string
    {
        return $twig->render('universal.twig', [
            'universal' => [
                'airship' => [
                    'trusted-supplier' => 'paragonie'
                ],
                'auto-update' => [
                    'check' => (int) $this->data['config']['auto-update']['check'],
                    'major' => !empty($this->data['config']['auto-update']['major']),
                    'minor' => !empty($this->data['config']['auto-update']['minor']),
                    'patch' => !empty($this->data['config']['auto-update']['patch']),
                    'test' => false // Unsure if we'll ever allow this
                ],
                'cookie_index' => $this->data['config']['cookie_index'],
                'ledger' => $this->data['config']['ledger'],
                'guzzle' => [],
                    // This can be customized later, but by default we just pass [].
                'session_index' => $this->data['config']['session_index'],
                'tor-only' => !empty($this->data['config']['tor-only'])
            ]
        ]);
    }
    
    protected function finalDatabaseSetup()
    {
        $this->db = $this->finalDatabasePrimary();
        
        // Let's iterate through the SQL files and run them all
        $driver = $this->db->getDriver();
        $files = \Airship\list_all_files(
            ROOT.'/Installer/sql/'.$driver,
            'sql'
        );
        \sort($files);
        foreach ($files as $file) {
            $query = \file_get_contents($file);
            try {
                $this->db->exec($query);
            } catch (\PDOException $e) {
                var_dump($e->getMessage());
                var_dump($query); 
                exit;
            }
        }
        switch ($driver) {
            case 'pgsql':
                return $this->databaseFinalPgsql();
        }
    }
    
    /**
     * Get the primary database (part of the finalize process)
     */
    protected function finalDatabasePrimary(): Database
    {
        $databases = \Airship\loadJSON(ROOT.'/config/databases.json');
        $dbconf = $databases['default'][0];
        $conf = [
            isset($dbconf['dsn'])
                ? $dbconf['dsn']
                : $dbconf
        ];

        if (isset($dbconf['username']) && isset($dbconf['password'])) {
            $conf[] = $dbconf['username'];
            $conf[] = $dbconf['password'];
            if (isset($dbconf['options'])) {
                $conf[] = $dbconf['options'];
            }
        } elseif (isset($dbconf['options'])) {
            $conf[1] = '';
            $conf[2] = '';
            $conf[3] = $dbconf['options'];
        }
        if (empty($conf)) {

            die();
        }
        return Database::factory(...$conf);
    }

    /**
     * Reset the sequence values.
     */
    protected function databaseFinalPgsql()
    {
        /**
         * 'table' +> 'primary_key_column'
         */
        $map = [
            'airship_auth_tokens' =>
                'tokenid',
            'airship_custom_dir' =>
                'directoryid',
            'airship_custom_page' =>
                'pageid',
            'airship_custom_page_version' =>
                'versionid',
            'airship_custom_redirect' =>
                'redirectid',
            'airship_dirs' =>
                'directoryid',
            'airship_files' =>
                'fileid',
            'airship_groups' =>
                'groupid',
            'airship_perm_actions' =>
                'actionid',
            'airship_perm_contexts' =>
                'contextid',
            'airship_perm_rules' =>
                'ruleid',
            'airship_users' =>
                'userid',
            'airship_user_preferences' =>
                'preferenceid',

            'hull_blog_authors' =>
                'authorid',
            'hull_blog_author_photos' =>
                'photoid',
            'hull_blog_categories' =>
                'categoryid',
            'hull_blog_comments' =>
                'commentid',
            'hull_blog_comment_versions' =>
                'versionid',
            'hull_blog_tags' =>
                'tagid',
            'hull_blog_posts' =>
                'postid',
            'hull_blog_post_versions' =>
                'versionid',
            'hull_blog_series' =>
                'seriesid',
            'hull_blog_series_items' =>
                'itemid'
        ];
        foreach ($map as $table => $primary_key_name) {
            $this->db->run(
                "SELECT setval(
                    '" . $table . "_" . $primary_key_name ."_seq',
                        COALESCE(
                            (
                                SELECT MAX(" . $primary_key_name .") + 1 FROM " . $table . "
                            ),
                            1
                        ),
                    FALSE
                );"
            );
        }
    }

    /**
     * Create the admin user account
     */
    protected function finalProcessAdminAccount()
    {
        $this->db->insert('airship_users', [
            'username' => $this->data['admin']['username'],
            'password' => $this->data['admin']['passphrase'],
            'uniqueid' => \Airship\uniqueId()
        ]);
        
        // This SHOULD be 1...
        $userid = $this->db->cell(
            'SELECT userid FROM airship_users WHERE username = ?',
            $this->data['admin']['username']
        );
        $this->db->insert('airship_users_groups', [
            'userid' => $userid,
            'groupid' => self::GROUP_ADMIN
        ]);
        
        // Log in as the user
        $_SESSION['userid'] = $userid;
    }

    /**
     * Create the default pages (about, contact).
     */
    protected function finalDefaultPages()
    {
        foreach (\Airship\list_all_files(ROOT.'/Installer/default_pages') as $file) {
            $filedata = \file_get_contents($file);
            if (\preg_match('#/([^./]+).md$#', $file, $m)) {
                $pageid = $this->db->insertGet(
                    'airship_custom_page',
                    [
                        'cabin' => 'Hull',
                        'url' => $m[1],
                        'active' => true,
                        'cache' => false
                    ],
                    'pageid'
                );
                $this->db->insert(
                    'airship_custom_page_version',
                    [
                        'page' => $pageid,
                        'uniqueid' => \Airship\uniqueId(),
                        'published' => true,
                        'formatting' => 'Markdown',
                        'bridge_user' => 1,
                        'body' => $filedata,
                        'metadata' => '[]',
                        'raw' => false
                    ]
                );
            }
        }
    }

    /**
     * The last phase of the installer
     */
    protected function finalShutdown()
    {
        $this->autoSave = false;
        $this->finalDefaultPages();
        \unlink(ROOT.'/tmp/installing.json');
        \Airship\redirect('/');
    }
}
