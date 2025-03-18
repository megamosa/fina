<?php
namespace MagoArab\CdnIntegration\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use MagoArab\CdnIntegration\Helper\Data as Helper;

class JsLibrariesScanner
{
    /**
     * @var Helper
     */
    protected $helper;
    
    /**
     * @var Filesystem
     */
    protected $filesystem;
    
    /**
     * @var array
     */
    protected $importantDirectories = [
        // Core JavaScript libraries
        'mage/requirejs',
        'mage/utils',
        'mage/translate',
        'jquery',
        'jquery/ui-modules',
        'jquery-ui-modules',
        'underscore',
        'knockoutjs',
        'Magento_Ui/js/lib',
        'Magento_Ui/js/core',
        'Magento_Ui/js/form',
        'Magento_Ui/js/grid',
        'Magento_Ui/js/modal',
        // Common UI components
        'Magento_Theme',
        'Magento_Catalog/js',
        'Magento_Checkout/js',
        'Magento_Customer/js',
        'Magento_Search/js',
        // Common libraries for fonts
        'fonts',
		'font-awesome/fonts',
		'simple-line-icons/fonts',
		'icon-fonts/font',
        'css/fonts'
    ];
    
    /**
     * @var array
     */
    protected $importantPatterns = [
        '*.min.js',
        'bundle*.js',
        'requirejs-config.js',
        'mage/requirejs/mixins.js',
        'mage/bootstrap.js',
        '*.woff',
        '*.woff2',
        '*.ttf',
        '*.eot',
        '*.otf',
        'knockout.js',
        'jquery*.js',
        'require.js'
    ];
    
    /**
     * @param Helper $helper
     * @param Filesystem $filesystem
     */
    public function __construct(
        Helper $helper,
        Filesystem $filesystem
    ) {
        $this->helper = $helper;
        $this->filesystem = $filesystem;
    }
    
    /**
     * Scan static directories for important JavaScript libraries
     *
     * @return array
     */
    public function scanJsLibraries()
    {
        $result = [];
        
        try {
            $staticDir = $this->filesystem->getDirectoryRead(DirectoryList::STATIC_VIEW);
            $staticPath = $staticDir->getAbsolutePath();
            
            $this->helper->log("Starting JavaScript libraries scan from: {$staticPath}", 'info');
            
            // Scan for theme directories first
            $themePaths = $this->scanForThemePaths($staticPath);
            
            foreach ($themePaths as $themePath) {
                // Scan important directories within each theme
                foreach ($this->importantDirectories as $directory) {
                    $fullPath = rtrim($themePath, '/') . '/' . $directory;
                    $this->helper->log("Checking directory: {$fullPath}", 'debug');
                    
                    if (is_dir($fullPath)) {
                        $files = $this->scanDirectoryRecursively($fullPath, $staticPath);
                        $result = array_merge($result, $files);
                    }
                }
                
                // Scan for important file patterns
                foreach ($this->importantPatterns as $pattern) {
                    $matches = glob($themePath . '/' . $pattern);
                    if ($matches) {
                        foreach ($matches as $match) {
                            $relativePath = str_replace($staticPath, '', $match);
                            $urlPath = '/static' . $relativePath;
                            $result[] = $urlPath;
                        }
                    }
                }
            }
            
            // Deduplicate and sort
            $result = array_unique($result);
            sort($result);
            
            $this->helper->log("Found " . count($result) . " important JavaScript libraries and files", 'info');
            
            return $result;
        } catch (\Exception $e) {
            $this->helper->log("Error scanning JavaScript libraries: " . $e->getMessage(), 'error');
            return [];
        }
    }
    
    /**
     * Scan for theme paths in the static directory
     *
     * @param string $staticPath
     * @return array
     */
    protected function scanForThemePaths($staticPath)
    {
        $themePaths = [];
        
        // Look for frontend and adminhtml areas
        $areas = ['frontend', 'adminhtml'];
        
        foreach ($areas as $area) {
            $areaPath = $staticPath . $area;
            
            if (is_dir($areaPath)) {
                // Get all vendor directories
                $vendors = glob($areaPath . '/*', GLOB_ONLYDIR);
                
                foreach ($vendors as $vendor) {
                    // Get all theme directories
                    $themes = glob($vendor . '/*', GLOB_ONLYDIR);
                    
                    foreach ($themes as $theme) {
                        // Get all locale directories
                        $locales = glob($theme . '/*', GLOB_ONLYDIR);
                        
                        foreach ($locales as $locale) {
                            $themePaths[] = $locale;
                        }
                    }
                }
            }
        }
        
        $this->helper->log("Found " . count($themePaths) . " theme paths for scanning", 'debug');
        
        return $themePaths;
    }
    
    /**
     * Scan directory recursively for files
     *
     * @param string $directory
     * @param string $basePath
     * @return array
     */
    protected function scanDirectoryRecursively($directory, $basePath)
    {
        $result = [];
        
        if (!is_dir($directory)) {
            return $result;
        }
        
        $files = scandir($directory);
        
        foreach ($files as $file) {
            if ($file == '.' || $file == '..') {
                continue;
            }
            
            $path = $directory . '/' . $file;
            
            if (is_dir($path)) {
                // Recursively scan subdirectories
                $subDirFiles = $this->scanDirectoryRecursively($path, $basePath);
                $result = array_merge($result, $subDirFiles);
            } else {
                // Check file extension
                $extension = pathinfo($path, PATHINFO_EXTENSION);
                
                // Add important file types
                if (in_array($extension, ['js', 'woff', 'woff2', 'ttf', 'eot', 'otf', 'svg'])) {
                    $relativePath = str_replace($basePath, '', $path);
                    $urlPath = '/static' . $relativePath;
                    $result[] = $urlPath;
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Get important static URLs for key libraries
     * 
     * @return array
     */
    public function getImportantStaticUrls()
    {
        // Base URLs for important libraries
        $importantLibraries = [
            '/static/frontend/*/*/*/mage/requirejs/mixins.js',
            '/static/frontend/*/*/*/requirejs/require.js',
            '/static/frontend/*/*/*/mage/utils/*.js',
            '/static/frontend/*/*/*/jquery.js',
            '/static/frontend/*/*/*/jquery-ui.js',
            '/static/frontend/*/*/*/jquery/*.js',
            '/static/frontend/*/*/*/jquery/ui-modules/*.js',
            '/static/frontend/*/*/*/knockout.js',
            '/static/frontend/*/*/*/mage/translate.js',
            '/static/frontend/*/*/*/mage/menu.js',
            '/static/frontend/*/*/*/mage/tabs.js',
            '/static/frontend/*/*/*/Magento_Ui/js/lib/*.js',
            '/static/frontend/*/*/*/Magento_Ui/js/core/*.js',
            '/static/frontend/*/*/*/Magento_Ui/js/form/*.js',
            '/static/frontend/*/*/*/Magento_Ui/js/modal/*.js',
            '/static/frontend/*/*/*/Magento_Checkout/js/view/*.js',
            '/static/frontend/*/*/*/Magento_Catalog/js/*.js',
            // Font patterns
            '/static/frontend/*/*/*/fonts/*.woff2',
            '/static/frontend/*/*/*/fonts/*.woff',
            '/static/frontend/*/*/*/fonts/*.ttf',
            '/static/frontend/*/*/*/fonts/*.eot',
            '/static/frontend/*/*/*/fonts/*.otf',
            '/static/frontend/*/*/*/css/fonts/*.woff2',
            '/static/frontend/*/*/*/css/fonts/*.woff',
            '/static/frontend/*/*/*/css/fonts/*.ttf',
            '/static/frontend/*/*/*/css/fonts/*.eot',
            '/static/frontend/*/*/*/css/fonts/*.otf'
        ];
        
        return $importantLibraries;
    }
}