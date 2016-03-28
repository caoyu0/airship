<?php
declare(strict_types=1);

// Start the session
if (!\session_id()) {
    if (isset($state->session_config)) {
        \session_start($state->session_config);
    } else {
        \session_start([
            'cookie_httponly' => true
        ]);
    }
}

if (!isset($_SESSION['created_canary'])) {
    // We haven't seen this session ID before
    $oldSession = $_SESSION;
    // Create the canary
    $oldSession['created_canary'] = (new \DateTime('NOW'))
        ->format('Y-m-d\TH:i:s');
    // Make sure $_SESSION is empty before we regenerate IDs
    $_SESSION = [];
    \session_regenerate_id(true);
    // Now let's restore the superglobal
    $_SESSION = $oldSession;
}
