<?php
declare(strict_types=1);
if (!\defined('ROOT')) {
    require_once __DIR__.'/preload.php';
}
/**
 * 1. Load all of the cabins, in order, and set up the autoloader.
 */
$gears = \Airship\loadJSON(ROOT.'/config/gears.json');
foreach ($gears as $gear) {
    $ns = isset($gear['supplier'])
        ? $gear['supplier'].'\\'.$gear['name']
        : $gear['name'];
    // Load the gears first:
    \Airship\autoload(
        '\\Airship\\Gears\\'.$ns, 
        '~/Gears/'.\str_replace('\\', '/', $ns)
    );
}

/**
 * 2. After loading the gears, we set up the cabins
 */
require_once ROOT.'/cabins.php';
\Airship\autoload('\\Airship\\Cabin', '~/Cabin');

/**
 * 3. Let's bootstrap the routes and other configuration
 */
$active = $state->cabins[$state->active_cabin];
$state->lang = isset($active['lang']) 
    ? $active['lang']
    : 'en-us';

// Let's start our session:
require_once ROOT.'/session.php';

// Let's set the current language:
$lang = \preg_replace_callback(
    '#([A-Za-z]+)\-([a-zA-Z]+)#',
    function($matches) {
        return \strtolower($matches[1]).'_'.\strtoupper($matches[2]);
    },
    $state->lang
).
'.UTF-8';
\putenv('LANG='.$lang);

// Overload the active template
if (isset($active['data']['base_template'])) {
    $state->base_template = $active['data']['base_template'];
} else {
    $state->base_template = 'base.twig';
}

$twigLoader = new \Twig_Loader_Filesystem(
    ROOT.'/Cabin/'.$active['name'].'/Lens'
);

$lensLoad = [];
// Load all the gadgets, which can act on $twigLoader
include ROOT.'/config/gadgets.php';

$twigEnv = new \Twig_Environment($twigLoader, [
    'debug' => true
]);
$twigEnv->addExtension(new \Twig_Extension_Debug());

$lens = \Airship\Engine\Gears::get('Lens', $twigEnv);
include ROOT.'/config/lens.php';
if (\file_exists(ROOT.'/Cabin/'.$active['name'].'/lens.php')) {
    include ROOT.'/Cabin/'.$active['name'].'/lens.php';
}
if (\file_exists(ROOT.'/config/Cabin/'.$active['name'].'/twig_vars.json')) {
    $_settings = \Airship\loadJSON(
        ROOT.'/config/Cabin/'.$active['name'].'/twig_vars.json'
    );
    $lens->addGlobal(
        'SETTINGS',
        $_settings
    );
}
// Now let's load all the lens.php files
foreach ($lensLoad as $incl) {
    include $incl;
}

/**
 * Let's load up the databases
 */
$db = [];
require ROOT.'/database.php';

$universal = \Airship\loadJSON(ROOT.'/config/universal.json');
$state->universal = $universal;

$manifest = \Airship\loadJSON(ROOT.'/config/manifest.json');
$state->manifest = $manifest;

$htmlpurifier = new \HTMLPurifier(
    \HTMLPurifier_Config::createDefault()
);
$state->HTMLPurifier = $htmlpurifier;

/**
 * Load up all of the keys used by the application:
 */
require_once ROOT.'/keys.php';

/**
 * Set up the logger
 */
require_once ROOT."/config/logger.php";

/**
 * Automatic security updates
 */
$hail = \Airship\Engine\Gears::get(
    'Hail',
    new \GuzzleHttp\Client($state->universal['guzzle'])
);
$state->hail = $hail;
