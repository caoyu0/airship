<?php
declare(strict_types=1);
namespace Airship\Cabin\Bridge\Landing;

use \Airship\Engine\State;

require_once __DIR__.'/gear.php';

class Gears extends AdminOnly
{
    /**
     * @route gears
     */
    public function index()
    {
        $this->lens('gears');
    }

    public function manage(string $cabinName = '')
    {
        $this->lens('gear_manage');
    }
}