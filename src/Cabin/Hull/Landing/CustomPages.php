<?php
declare(strict_types=1);
namespace Airship\Cabin\Hull\Landing;

use \Airship\Cabin\Hull\Exceptions\CustomPageNotFoundException;
use \Airship\Cabin\Hull\Exceptions\RedirectException;
use \Airship\Engine\State;
use \Gregwar\RST\Parser as RSTParser;
use \League\CommonMark\CommonMarkConverter;
use \Psr\Log\LogLevel;

require_once __DIR__.'/gear.php';

class CustomPages extends LandingGear
{
    protected $cabin = 'Hull';

    public function airshipLand()
    {
        $this->pages = $this->blueprint('CustomPages');
        $this->pages->setCabin($this->cabin);
    }

    /**
     * This interrupts requests if all else fails.
     * @param string[] ...$args
     */
    public function routeNotFound(...$args)
    {
        $dirs = $args;
        $file = \array_pop($dirs);

        // First: Do we have a custom page at this endpoint?
        try {
            if ($this->serveCustomPage($file, $dirs)) {
                return;
            }
        } catch (CustomPageNotFoundException $ex) {
            $this->log(
                'Custom page not found',
                LogLevel::INFO,
                [
                    'cabin' => $this->cabin,
                    'dirs' => $dirs,
                    'exception' => \Airship\throwableToArray($ex),
                    'file' => $file
                ]
            );
        }

        // Second: Is there a redirect at this endpoint?
        try {
            $path = \implode('/', $args);
            if ($this->pages->serveRedirect($path)) {
                return;
            }
        } catch (RedirectException $ex) {
            $this->log(
                'Redirect missed',
                LogLevel::INFO
            );
        }

        // Finally: Return a 4o4
        return $this->lens('404');
    }

    /**
     * Public API entry point for serving a custom page
     *
     * @param string $file
     * @param array $dirs
     * @return bool
     */
    protected function serveCustomPage(string $file, array $dirs = []): bool
    {
        return $this->serveFile($file, $this->pages->getParentDir($dirs));
    }

    /**
     * Serve a file
     *
     * @param string $file
     * @param int $directoryId
     * @return bool
     */
    protected function serveFile(string $file, int $directoryId): bool
    {
        $page = $this->pages->getPage($file, $directoryId);
        if (!empty($page)) {
            return $this->serveLatestVersion($page);
        }
        return false;
    }

    /**
     * Server the latest version of a custom page.
     *
     * @param array $page
     * @return bool
     */
    protected function serveLatestVersion(array $page): bool
    {
        $latest = $this->pages->getLatestVersion((int) $page['pageid']);
        if (empty($latest)) {
            return false;
        }
        $vars = $latest['metadata'];
        $vars['rendered_content'] = $this->render($latest);

        if ($page['cache']) {
            return $this->stasis('custom', $vars);
        } else {
            return $this->lens('custom', $vars);
        }
    }

    /**
     * Render a custom page
     *
     * @param array $latest
     * @return string
     */
    protected function render(array $latest): string
    {
        $state = State::instance();
        switch ($latest['formatting']) {
            case 'Markdown':
                $md = new CommonMarkConverter;
                if (empty($latest['raw'])) {
                    $state->HTMLPurifier->purify(
                        $md->convertToHtml($latest['body'])
                    );
                }
                return $md->convertToHtml($latest['body']);
            case 'RST':
                $rst = new RSTParser;
                if (empty($latest['raw'])) {
                    $state->HTMLPurifier->purify(
                        $rst->parse($latest['body'])
                    );
                }
                return $rst->parse($latest['body']);
            case 'HTML':
            case 'Rich Text':
            default:
                if (empty($latest['raw'])) {
                    return $state->HTMLPurifier->purify($latest['body']);
                }
                return $latest['body'];
        }
    }
}
