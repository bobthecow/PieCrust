<?php

namespace PieCrust\Baker;

use \Exception;
use PieCrust\IPage;
use PieCrust\PieCrustException;
use PieCrust\Page\PageRenderer;
use PieCrust\Util\PageHelper;
use PieCrust\Util\PathHelper;


/**
 * A class responsible for baking a PieCrust page.
 */
class PageBaker
{
    /**
     * Index filename.
     */
    const BAKE_INDEX_DOCUMENT = 'index.html';
    
    protected $bakeDir;
    protected $parameters;
    
    protected $paginationDataAccessed;
    /**
     * Gets whether pagination data was accessed during baking.
     */
    public function wasPaginationDataAccessed()
    {
        return $this->paginationDataAccessed;
    }
    
    /**
     * Gets the number of baked pages.
     */
    public function getPageCount()
    {
        return count($this->bakedFiles);
    }
    
    protected $bakedFiles;
    /**
     * Gets the files that were baked by the last call to 'bake()'.
     */
    public function getBakedFiles()
    {
        return $this->bakedFiles;
    }
    
    /**
     * Creates a new instance of PageBaker.
     */
    public function __construct($bakeDir, array $parameters = array())
    {
        $this->bakeDir = rtrim(str_replace('\\', '/', $bakeDir), '/') . '/';
        $this->parameters = array_merge(
            array(
                'copy_assets' => false
            ), 
            $parameters
        );
    }
    
    /**
     * Bakes the given page. Additional template data can be provided, along with
     * a specific set of posts for the pagination data.
     */
    public function bake(IPage $page, array $extraData = null)
    {
        try
        {
            $this->bakedFiles = array();
            $this->paginationDataAccessed = false;
            
            $pageRenderer = new PageRenderer($page);
            
            $hasMorePages = true;
            while ($hasMorePages)
            {
                $this->bakeSinglePage($pageRenderer, $extraData);
                
                $data = $page->getPageData();
                if ($data and isset($data['pagination']))
                {
                    $paginator = $data['pagination'];
                    $hasMorePages = ($paginator->wasPaginationDataAccessed() and 
                                     $paginator->hasMorePages());
                    if ($hasMorePages)
                    {
                        $page->setPageNumber($page->getPageNumber() + 1);
                        // setPageNumber() resets the page's data, so when we 
                        // enter bakeSinglePage again in the next loop, we have 
                        // to re-set the extraData and all other stuff.
                    }
                }
            }
        }
        catch (Exception $e)
        {
            throw new PieCrustException("Error baking page '" . $page->getUri() . "' (p" . $page->getPageNumber() . "): " . $e->getMessage(), 0, $e);
        }
    }
    
    protected function bakeSinglePage(PageRenderer $pageRenderer, array $extraData = null)
    {
        $page = $pageRenderer->getPage();
        
        // Set the extra template data before the page's data is computed.        
        if ($extraData != null)
            $page->setExtraPageData($extraData);

        // This is usually done in the PieCrustBaker, but we'll do it here too
        // because the `PageBaker` could be used on its own.
        if ($this->parameters['copy_assets'])
            $page->setAssetUrlBaseRemap("%site_root%%uri%");
        
        // Figure out the output HTML path.
        $bakePath = $this->bakeDir;
        $isSubPage = ($page->getPageNumber() > 1);
        $prettyUrls = PageHelper::getConfigValue($page, 'pretty_urls', 'site');
        if ($prettyUrls)
        {
            // Output will be one of:
            // - `uri/name/index.html` (if not a sub-page).
            // - `uri/name/<n>/index.html` (if a sub-page, where <n> is the page number).
            $bakePath .= $page->getUri() . (($page->getUri() == '') ? '' : '/');
            if ($isSubPage)
                $bakePath .= $page->getPageNumber() . '/';
            $bakePath .= self::BAKE_INDEX_DOCUMENT;
        }
        else
        {
            // Output will be one of:
            // - `uri/name.html` (if not a sub-page).
            // - `uri/name/<n>.html` (if a sub-page, where <n> is the page number).
            // If the page has an extension different than `.html`, use that instead.
            $name = $page->getUri();
            $extension = pathinfo($page->getUri(), PATHINFO_EXTENSION);
            if ($extension)
                $name = substr($name, 0, strlen($name) - strlen($extension) - 1);
            $bakePath .= (($page->getUri() == '') ? 'index' : $name);
            if ($isSubPage)
                $bakePath .= '/' . $page->getPageNumber();
            if (!$extension)
                $extension = 'html';
            $bakePath .= '.' . $extension;
        }

        // If we're using portable URLs, change the site root to a relative
        // path from the page's directory.
        $savedSiteRoot = null;
        $portableUrls = $page->getApp()->getConfig()->getValue('baker/portable_urls');
        if ($portableUrls)
        {
            $siteRoot = '';
            $curDir = dirname($bakePath);
            while (strlen($curDir) > strlen($this->bakeDir))
            {
                $siteRoot .= '../';
                $curDir = dirname($curDir);
            }
            if ($siteRoot == '')
                $siteRoot = './';

            $savedSiteRoot = $page->getApp()->getConfig()->getValueUnchecked('site/root');
            $page->getApp()->getConfig()->setValue('site/root', $siteRoot);

            // We need to force-reevaluate the URI decorators because they cache
            // the site root in them.
            // TODO: figure a way to make the configuration do it itself, maybe as 
            // part of the validation hooks.
            $page->getApp()->getEnvironment()->getUriDecorators(true);

            // We also need to unload all loaded pages because their rendered
            // contents will probably be invalid now that the site root changed.
            $repo = $page->getApp()->getEnvironment()->getPageRepository();
            foreach ($repo->getPages() as $p)
            {
                $p->unload();
            }
        }
        
        // Render the page.
        $bakedContents = $pageRenderer->get();

        // Get some objects we need.
        $data = $page->getPageData();
        $assetor = $data['asset'];
        $paginator = $data['pagination'];
        
        // Copy the page.
        PathHelper::ensureDirectory(dirname($bakePath));
        file_put_contents($bakePath, $bakedContents);
        $this->bakedFiles[] = $bakePath;
        
        // Copy any used assets for the first sub-page.
        if (!$isSubPage and $this->parameters['copy_assets'])
        {
            if ($prettyUrls)
            {
                $bakeAssetDir = dirname($bakePath) . '/';
            }
            else
            {
                $bakePathInfo = pathinfo($bakePath);
                $bakeAssetDir = $bakePathInfo['dirname'] . '/' . 
                                (($page->getUri() == '') ? '' : $bakePathInfo['filename']) . '/';
            }
            
            $assetPaths = $assetor->getAssetPathnames();
            if ($assetPaths != null)
            {
                PathHelper::ensureDirectory($bakeAssetDir);
                foreach ($assetPaths as $assetPath)
                {
                    $destinationAssetPath = $bakeAssetDir . basename($assetPath);
                    if (@copy($assetPath, $destinationAssetPath) == false)
                        throw new PieCrustException("Can't copy '".$assetPath."' to '".$destinationAssetPath."'.");
                }
            }
        }
        
        // Remember a few things.
        $this->paginationDataAccessed = ($this->paginationDataAccessed or $paginator->wasPaginationDataAccessed());

        // Cleanup.
        if ($savedSiteRoot)
            $page->getApp()->getConfig()->setValue('site/root', $savedSiteRoot);
    }
}