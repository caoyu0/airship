<?php
declare(strict_types=1);
namespace Airship\Engine;

use \Airship\Engine\Continuum\{
    Supplier,
    Airship as AirshipUpdater,
    Cabin as CabinUpdater,
    Gadget as GadgetUpdater,
    Gear as GearUpdater
};

class Continuum
{
    protected $hail;
    
    public function __construct(Hail $hail = null)
    {
        $config = \Airship\Engine\State::instance();
        if (empty($hail)) {
            $this->hail = $config->hail;
        } else {
            $this->hail = $hail;
        }
    }
    
    /**
     * Do we need to run the update process?
     * 
     * @return bool
     */
    public function needsUpdate(): bool
    {
        $config = \Airship\Engine\State::instance();
        
        if (\is_readable(ROOT.'/tmp/last_update_check.txt')) {
            $last = \file_get_contents(ROOT.'/tmp/last_update_check.txt');
            return (time() - $last) > $config->universal['auto-update']['check'];
        } else {
            return true;
        }
    }
    
    /**
     * Do we need to do an update check?
     * 
     * @param bool $force Force start the update check?
     * 
     */
    public function checkForUpdates(bool $force = false)
    {
        $update = $force
            ? true
            : $this->needsUpdate();
        
        if ($update) {
            // Load all the suppliers
            $this->getSupplier();
            
            // Actually perform the update check
            $this->doUpdateCheck();
        }
    }

    /**
     * Do the update check,
     *
     * 1. Update all cabins
     * 2. Update all gadgets
     * 3. Update the core
     */
    public function doUpdateCheck()
    {
        $config = \Airship\Engine\State::instance();
        // First, update each cabin
        foreach ($this->getCabins() as $cabin) {
            if ($cabin instanceof CabinUpdater) {
                $cabin->autoUpdate();
            }
        }
        // Next, update each gadget
        foreach ($this->getGadgets() as $gadget) {
            if ($gadget instanceof GadgetUpdater) {
                $gadget->autoUpdate();
            }
        }
        // Finally, let's update the core
        $s = $config->universal['airship']['trusted-supplier'];
        if (!empty($s)) {
            $ha = new AirshipUpdater(
                $this->hail,
                $this->getSupplier($s)
            );
            $ha->autoUpdate();
        }
    }
    
    /**
     * Get an array of CabinUpdater objects
     * 
     * @return array [CabinUpdater, CabinUpdater, ...]
     */
    public function getCabins() : array
    {
        $cabins = [];
        foreach (\Airship\list_all_files(ROOT.'/Cabin/') as $file) {
            if (\is_dir($file) && \is_readable($file.'/manifest.json')) {
                $manifest = \Airship\loadJSON($file.'/manifest.json');
                $dirname = \preg_replace('#^.+?/([^\/]+)$#', '$1', $file);
                if (!empty($manifest['supplier'])) {
                    $cabins[$dirname] = new CabinUpdater(
                        $this->hail,
                        $manifest,
                        $this->getSupplier($manifest['supplier'])
                    );
                }
            }
        }
        return $cabins;
    }
    
    /**
     * Get an array of GadgetUpdater objects
     * 
     * @return array [GadgetUpdater, GadgetUpdater, ...]
     */
    public function getGadgets() : array
    {
        $gadgets = [];
        // First, each cabin's gadgets:
        foreach (\Airship\list_all_files(ROOT.'/Cabin/') as $dir) {
            if (\is_dir($dir.'/Gadgets')) {
                foreach (\Airship\list_all_files($dir.'/Gadgets/', 'phar') as $file) {
                    $manifest = $this->getPharManifest($file);
                    $name = \preg_replace('#^.+?/([^\/]+)\.phar$#', '$1', $file);
                    list($supplier, $pkg) = explode('--', $name);
                    $gadgets[$name] = new GadgetUpdater(
                        $this->hail,
                        $manifest,
                        $this->getSupplier($supplier),
                        $file
                    );
                }
            }
        }
        // First the universal gadgets:
        foreach (\Airship\list_all_files(ROOT.'/Gadgets/', 'phar') as $file) {
            $manifest = $this->getPharManifest($file);
            $name = \preg_replace('#^.+?/([^\/]+)\.phar$#', '$1', $file);
            list($supplier, $pkg) = explode('--', $name);
            $orig = ''.$name;
            // Handle name collisions
            while (isset($gadgets[$name])) {
                $i = isset($i) ? ++$i : 2;
                $name = $orig . '-' . $i;
            }
            $gadgets[$name] = new GadgetUpdater(
                $this,
                $manifest,
                $this->getSupplier($supplier),
                $file
            );
        }
        return $gadgets;
    }
    
    /**
     * Get metadata from the Phar
     * 
     * @param string $file
     * @return array
     */
    public function getPharManifest(string $file) : array
    {
        $phar = new \Phar($file);
        $meta = $phar->getMetadata();
        if (empty($meta)) {
            /** @todo handle edge cases here if people neglect to use barge */
        }
        return $meta;
    }
    
    /**
     * Load all of the supplier's Ed25519 public keys
     * 
     * @param string $supplier
     * @param boolean $force_flush
     * @return array|null
     */
    public function getSupplier(
        string $supplier = '',
        bool $force_flush = false
    ) {
        if ($force_flush || empty($this->keyCache)) {
            $keyCache = \Airship\loadJSON(ROOT.'/config/supplier_keys.json');
            foreach ($keyCache as $v => $data) {
                $keyCache[$v] = new Supplier($v, $data);
            }
            $this->keyCache = $keyCache;
        }
        if (empty($supplier)) {
            return $this->keyCache;
        } elseif(isset($this->keyCache[$supplier])) {
            return $this->keyCache[$supplier];
        }
        return [];
    }
}
