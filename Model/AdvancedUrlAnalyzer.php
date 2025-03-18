<?php
namespace MagoArab\CdnIntegration\Model;

use MagoArab\CdnIntegration\Helper\Data as Helper;
use MagoArab\CdnIntegration\Model\JsLibrariesScanner;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Store\Model\StoreManagerInterface;

class AdvancedUrlAnalyzer
{
    /**
     * @var Helper
     */
    protected $helper;
    
    /**
     * @var Curl
     */
    protected $curl;
    
    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;
    
    /**
     * @var JsLibrariesScanner
     */
    protected $jsLibrariesScanner;
    
    /**
     * @var array
     */
    protected $visitedUrls = [];
    
    /**
     * @var array
     */
    protected $discoveredAssets = [];
    
    /**
     * @var int
     */
    protected $maxPagesToVisit = 5;
    
    /**
     * @var int
     */
    protected $visitedCount = 0;
    
    /**
     * @var string
     */
    protected $baseUrl;
    
    /**
     * @var array
     */
    protected $progressCallback = null;
    
    /**
     * @param Helper $helper
     * @param Curl $curl
     * @param StoreManagerInterface $storeManager
     * @param JsLibrariesScanner $jsLibrariesScanner
     */
    public function __construct(
        Helper $helper,
        Curl $curl,
        StoreManagerInterface $storeManager,
        JsLibrariesScanner $jsLibrariesScanner
    ) {
        $this->helper = $helper;
        $this->curl = $curl;
        $this->storeManager = $storeManager;
        $this->jsLibrariesScanner = $jsLibrariesScanner;
    }
    
    /**
     * Set a callback function to report progress
     *
     * @param callable $callback
     * @return $this
     */
    public function setProgressCallback($callback)
    {
        $this->progressCallback = $callback;
        return $this;
    }
    
    /**
     * Report progress
     *
     * @param string $status
     * @param int $percent
     * @param string $detail
     * @return void
     */
    protected function reportProgress($status, $percent, $detail = '')
    {
        if (is_callable($this->progressCallback)) {
            call_user_func($this->progressCallback, $status, $percent, $detail);
        }
    }
    
    /**
     * Analyze store for static and media URLs
     *
     * @param string|null $startUrl
     * @param int $maxPages
     * @return array
     */
    public function analyze($startUrl = null, $maxPages = 5)
    {
        // Reset state
        $this->visitedUrls = [];
        $this->discoveredAssets = [];
        $this->visitedCount = 0;
        $this->maxPagesToVisit = $maxPages;
        
        if (!$startUrl) {
            $this->baseUrl = $this->storeManager->getStore()->getBaseUrl();
        } else {
            $this->baseUrl = $startUrl;
            
            // Ensure baseUrl ends with a slash
            if (substr($this->baseUrl, -1) !== '/') {
                $this->baseUrl .= '/';
            }
        }
        
        $this->helper->log("Starting advanced URL analysis from: {$this->baseUrl}", 'info');
        $this->reportProgress('Starting analysis', 5, "Preparing to analyze {$this->baseUrl}");
        
        // First, scan for important JS libraries
        $this->reportProgress('Scanning JavaScript libraries', 10, 'Searching for important JavaScript libraries...');
        $jsLibraries = $this->jsLibrariesScanner->scanJsLibraries();
        
        if (!empty($jsLibraries)) {
            $this->helper->log("Found " . count($jsLibraries) . " important JavaScript libraries", 'info');
            $this->reportProgress('JavaScript libraries found', 20, 'Found ' . count($jsLibraries) . ' important JavaScript libraries');
            
            // Add them to discovered assets
            $this->discoveredAssets = array_merge($this->discoveredAssets, $jsLibraries);
        } else {
            $this->helper->log("No important JavaScript libraries found", 'warning');
            $this->reportProgress('JavaScript libraries scan', 20, 'No important JavaScript libraries found');
        }
        
        // Start with the homepage
        $this->reportProgress('Crawling pages', 25, 'Starting with the homepage...');
        $this->crawlPage($this->baseUrl);
        
        // Crawling is complete
        $this->reportProgress('Crawling complete', 90, "Visited {$this->visitedCount} pages, found " . count($this->discoveredAssets) . " unique assets");
        $this->helper->log("Analysis complete. Visited {$this->visitedCount} pages, found " . 
            count($this->discoveredAssets) . " unique static/media assets.", 'info');
        
        // Process results and remove duplicates
        $result = $this->getDiscoveredAssets();
        $this->reportProgress('Analysis complete', 100, 'Found ' . count($result) . ' unique static and media URLs');
        
        return $result;
    }
    
    /**
     * Crawl a page and extract assets and links
     *
     * @param string $url
     * @return void
     */
    protected function crawlPage($url)
    {
        // Skip if we've already visited this URL or reached the limit
        if (in_array($url, $this->visitedUrls) || $this->visitedCount >= $this->maxPagesToVisit) {
            return;
        }
        
        $this->helper->log("Crawling page: {$url}", 'debug');
        $this->visitedUrls[] = $url;
        $this->visitedCount++;
        
        // Calculate progress percentage based on visited pages
        $progressPercent = 25 + (($this->visitedCount / $this->maxPagesToVisit) * 65);
        $this->reportProgress('Crawling', min(90, $progressPercent), "Crawling page {$this->visitedCount} of {$this->maxPagesToVisit}: {$url}");
        
        // Fetch the page content
        $content = $this->fetchUrl($url);
        if (empty($content)) {
            $this->helper->log("Failed to fetch content from: {$url}", 'warning');
            return;
        }
        
        // Extract and store static/media assets
        $assets = $this->extractAssets($content);
        $newAssetsCount = 0;
        
        foreach ($assets as $asset) {
            if (!in_array($asset, $this->discoveredAssets)) {
                $this->discoveredAssets[] = $asset;
                $newAssetsCount++;
            }
        }
        
        $this->reportProgress('Processing', min(90, $progressPercent), "Found {$newAssetsCount} new assets on page {$url}");
        
        // Extract links to other pages on the same domain
        $links = $this->extractLinks($content, $url);
        
        // Visit each link (depth-first)
        foreach ($links as $link) {
            if ($this->visitedCount < $this->maxPagesToVisit) {
                $this->crawlPage($link);
            } else {
                break;
            }
        }
    }
    
    /**
     * Fetch URL content using cURL
     *
     * @param string $url
     * @return string
     */
    protected function fetchUrl($url)
    {
        try {
            // Create new curl instance for each request to avoid conflicts
            $this->curl = new Curl();
            
            // Set browser-like user agent
            $this->curl->setOption(CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
            $this->curl->setOption(CURLOPT_RETURNTRANSFER, true);
            $this->curl->setOption(CURLOPT_FOLLOWLOCATION, true);
            $this->curl->setOption(CURLOPT_MAXREDIRS, 5);
            $this->curl->setOption(CURLOPT_TIMEOUT, 30);
            $this->curl->setOption(CURLOPT_SSL_VERIFYPEER, false);
            $this->curl->setOption(CURLOPT_SSL_VERIFYHOST, false);
            
            // Add accept headers to mimic browser
            $this->curl->addHeader('Accept', 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8');
            $this->curl->addHeader('Accept-Language', 'en-US,en;q=0.5');
            
            $this->curl->get($url);
            $statusCode = $this->curl->getStatus();
            
            if ($statusCode >= 200 && $statusCode < 300) {
                return $this->curl->getBody();
            }
            
            $this->helper->log("Error fetching URL {$url}: HTTP status {$statusCode}", 'warning');
            return '';
        } catch (\Exception $e) {
            $this->helper->log("Exception fetching URL {$url}: " . $e->getMessage(), 'error');
            return '';
        }
    }
    
    /**
     * Extract static and media assets from HTML content
     *
     * @param string $content
     * @return array
     */
    protected function extractAssets($content)
    {
        $assets = [];
        
        // Comprehensive patterns to find all types of static and media assets
        $patterns = [
            // CSS links
            '/<link[^>]*href=[\'"]([^\'"]+\.css(?:\?[^\'"]*)?)[\'"][^>]*>/i',
            
            // JavaScript files
            '/<script[^>]*src=[\'"]([^\'"]+\.js(?:\?[^\'"]*)?)[\'"][^>]*>/i',
            
            // Images
            '/<img[^>]*src=[\'"]([^\'"]+\.(png|jpg|jpeg|gif|svg|webp)(?:\?[^\'"]*)?)[\'"][^>]*>/i',
            
            // Background images in inline styles
            '/style=[\'"][^"\']*background(?:-image)?:\s*url\([\'"]?([^\'")+\s]+)[\'"]?\)[^"\']*[\'"]/',
            
            // Fonts and other assets in CSS url()
            '/url\([\'"]?([^\'")\s]+\.(woff|woff2|ttf|eot|otf)(?:\?[^\'"]*)?)[\'"]?\)/i',
            
            // Video and audio sources
            '/<(?:video|audio)[^>]*>.*?<source[^>]*src=[\'"]([^\'"]+)[\'"].*?<\/(?:video|audio)>/is',
            
            // Media in object/embed tags
            '/<(?:object|embed)[^>]*(?:data|src)=[\'"]([^\'"]+)[\'"][^>]*>/i',
            
            // Data attributes with URLs
            '/ data-[^=]*=[\'"]([^\'"]+\.(js|css|png|jpg|jpeg|gif)(?:\?[^\'"]*)?)[\'"][^>]*>/i',
            
            // SVG images in various positions
            '/<[^>]*?(?:href|src)=[\'"]([^\'"]+\.svg(?:\?[^\'"]*)?)[\'"][^>]*>/i',
            
            // Preload links
            '/<link[^>]*rel=[\'"]preload[\'"][^>]*href=[\'"]([^\'"]+)[\'"][^>]*>/i',
            
            // Import statements in styles
            '/@import\s+[\'"]([^\'"]+)[\'"]/i',
            
            // Font files with specific @font-face rules
            '/@font-face\s*\{[^}]*?src\s*:\s*url\([\'"]?([^\'"]+\.(woff|woff2|ttf|eot|otf)(?:\?[^\'"]*)?)[\'"]?\)/is'
        ];
        
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $content, $matches);
            
            if (isset($matches[1]) && !empty($matches[1])) {
                foreach ($matches[1] as $asset) {
                    // Skip data URLs and absolute URLs to other domains
                    if (strpos($asset, 'data:') === 0 || 
                        (strpos($asset, 'http') === 0 && strpos($asset, $this->baseUrl) !== 0)) {
                        continue;
                    }
                    
                    // Convert absolute URLs to relative paths
                    if (strpos($asset, $this->baseUrl) === 0) {
                        $asset = substr($asset, strlen($this->baseUrl) - 1); // -1 to keep the leading slash
                    }
                    
                    // Keep only static and media URLs
                    if (strpos($asset, '/static/') === 0 || strpos($asset, '/media/') === 0) {
                        $assets[] = $asset;
                    }
                }
            }
        }
        $portoFontPatterns = [
    // Font Awesome
    '/font-awesome\/fonts\/[^"\']+\.(woff2|woff|ttf|eot|otf)/i',
    // Simple Line Icons
    '/simple-line-icons\/fonts\/[^"\']+\.(woff2|woff|ttf|eot|otf)/i',
    // Porto Icons
    '/icon-fonts\/font\/[^"\']+\.(woff2|woff|ttf|eot|otf)/i',
];

foreach ($portoFontPatterns as $pattern) {
    preg_match_all($pattern, $content, $matches);
    foreach ($matches[0] as $fontUrl) {
        // إضافة المسار كاملاً
        $fullUrl = '/static/frontend/' . $fontUrl;
        $assets[] = $fullUrl;
    }
}
        // Special patterns for Magento-specific resources
        $magentoPatterns = [
            // Merged JS/CSS files
            '/\/static\/_cache\/merged\/[^"\')+\s]+/i',
            
            // Minified JS/CSS files
            '/\/static\/_cache\/minified\/[^"\')+\s]+/i',
            
            // RequireJS text plugin
            '/text!(\/static\/[^!]+)/i',
            
            // RequireJS define paths
            '/"(\/static\/[^"]+)"/i'
        ];
        
        foreach ($magentoPatterns as $pattern) {
            preg_match_all($pattern, $content, $matches);
            
            $matchesArray = !empty($matches[1]) ? $matches[1] : $matches[0];
            
            if (!empty($matchesArray)) {
                foreach ($matchesArray as $asset) {
                    if (strpos($asset, '/static/') === 0 || strpos($asset, '/media/') === 0) {
                        $assets[] = $asset;
                    }
                }
            }
        }
        
        // Look for font files specifically
        $fontPatterns = [
            // Font files in @font-face
            '/@font-face\s*\{[^}]*?src\s*:\s*url\([\'"]?([^\'"]+\.(woff2|woff|ttf|eot|otf)(?:\?[^\'"]*)?)[\'"]?\)/is',
            
            // Font files in various formats
            '/url\([\'"]?([^\'")+\s]+\.(woff2|woff|ttf|eot|otf)(?:\?[^\'"]*)?)[\'"]?\)/i'
        ];
        
        foreach ($fontPatterns as $pattern) {
            preg_match_all($pattern, $content, $matches);
            if (isset($matches[1]) && !empty($matches[1])) {
                foreach ($matches[1] as $fontUrl) {
                    // Skip data URLs
                    if (strpos($fontUrl, 'data:') === 0) {
                        continue;
                    }
                    
                    // Convert absolute URLs to relative paths
                    if (strpos($fontUrl, $this->baseUrl) === 0) {
                        $fontUrl = substr($fontUrl, strlen($this->baseUrl) - 1);
                    }
                    
                    // Keep only static and media URLs
                    if (strpos($fontUrl, '/static/') === 0 || strpos($fontUrl, '/media/') === 0) {
                        $assets[] = $fontUrl;
                    }
                }
            }
        }
        
        // Look for JSON data that might contain URLs
        if (preg_match_all('/\{[^}]+\}/m', $content, $jsonMatches)) {
            foreach ($jsonMatches[0] as $jsonString) {
                if (preg_match_all('/"(\/(?:static|media)\/[^"]+)"/i', $jsonString, $jsonPaths)) {
                    foreach ($jsonPaths[1] as $asset) {
                        $assets[] = $asset;
                    }
                }
            }
        }
        
        // Remove duplicates and sort
        $assets = array_unique($assets);
        sort($assets);
        
        return $assets;
    }
    
    /**
     * Extract links to other pages on the same domain
     *
     * @param string $content
     * @param string $baseUrl
     * @return array
     */
    protected function extractLinks($content, $baseUrl)
    {
        $links = [];
        $baseUrlDomain = parse_url($this->baseUrl, PHP_URL_HOST);
        
        // Find all links
        preg_match_all('/<a[^>]*href=[\'"]([^\'"#]+)[\'"][^>]*>/i', $content, $matches);
        
        if (isset($matches[1]) && !empty($matches[1])) {
            foreach ($matches[1] as $link) {
                // Skip JavaScript links, mailto, tel, etc.
                if (strpos($link, 'javascript:') === 0 || 
                    strpos($link, 'mailto:') === 0 || 
                    strpos($link, 'tel:') === 0) {
                    continue;
                }
                
                // Handle relative URLs
                if (strpos($link, 'http') !== 0) {
                    // Handle different relative path formats
                    if (strpos($link, '/') === 0) {
                        // Absolute path relative to domain
                        $domain = parse_url($this->baseUrl, PHP_URL_SCHEME) . '://' . $baseUrlDomain;
                        $link = $domain . $link;
                    } else {
                        // Relative to current path
                        $basePath = dirname($baseUrl);
                        $link = $basePath . '/' . $link;
                    }
                }
                
                // Only include links to the same domain
                $linkDomain = parse_url($link, PHP_URL_HOST);
                if ($linkDomain === $baseUrlDomain) {
                    // Make sure we don't include static/media URLs
                    $path = parse_url($link, PHP_URL_PATH);
                    if (strpos($path, '/static/') !== 0 && strpos($path, '/media/') !== 0) {
                        $links[] = $link;
                    }
                }
            }
        }
        
        return $links;
    }
    
    /**
     * Add additional important static URLs
     *
     * @return $this
     */
    public function addImportantStaticUrls()
    {
        $importantUrls = $this->jsLibrariesScanner->getImportantStaticUrls();
        
        // Process wildcard patterns
        foreach ($importantUrls as $pattern) {
            if (strpos($pattern, '*') !== false) {
                // This is a pattern, try to find matching URLs
                $normalizedPattern = str_replace(['*', '/'], ['.+', '\/'], $pattern);
                $normalizedPattern = '/' . $normalizedPattern . '/i';
                
                // Add any existing assets that match this pattern
                foreach ($this->discoveredAssets as $asset) {
                    if (preg_match($normalizedPattern, $asset)) {
                        // Make sure we don't have duplicates
                        if (!in_array($asset, $this->discoveredAssets)) {
                            $this->discoveredAssets[] = $asset;
                        }
                    }
                }
            } else {
                // Direct URL
                if (!in_array($pattern, $this->discoveredAssets)) {
                    $this->discoveredAssets[] = $pattern;
                }
            }
        }
        
        return $this;
    }
    
    /**
     * Get discovered assets
     *
     * @return array
     */
    public function getDiscoveredAssets()
    {
        // Add important static URLs before returning
        $this->addImportantStaticUrls();
        
        // Remove duplicates and sort
        $assets = array_unique($this->discoveredAssets);
        sort($assets);
        
        return $assets;
    }
}