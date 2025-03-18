<?php
namespace MagoArab\CdnIntegration\Model;

use MagoArab\CdnIntegration\Helper\Data as Helper;

class RequireJsHandler
{
    /**
     * @var Helper
     */
    protected $helper;

    /**
     * @param Helper $helper
     */
    public function __construct(
        Helper $helper
    ) {
        $this->helper = $helper;
    }
    
    /**
     * Apply CDN URL to RequireJS configuration file content
     *
     * @param string $content RequireJS config file content
     * @param string $cdnBaseUrl CDN base URL
     * @return string Modified content
     */
    public function processRequireJsConfig($content, $cdnBaseUrl)
    {
        if (empty($content) || empty($cdnBaseUrl)) {
            return $content;
        }
        
        $this->helper->log('Processing RequireJS configuration file', 'debug');
        
        // Try to parse the configuration
        $pathsStartPos = strpos($content, '"paths":');
        if ($pathsStartPos === false) {
            return $content;
        }
        
        // Find the opening brace after "paths":
        $pathsOpenBracePos = strpos($content, '{', $pathsStartPos);
        if ($pathsOpenBracePos === false) {
            return $content;
        }
        
        // Find the matching closing brace
        $openBraces = 1;
        $closeBracePos = $pathsOpenBracePos + 1;
        $contentLength = strlen($content);
        
        while ($openBraces > 0 && $closeBracePos < $contentLength) {
            $char = $content[$closeBracePos];
            if ($char === '{') {
                $openBraces++;
            } elseif ($char === '}') {
                $openBraces--;
            }
            $closeBracePos++;
        }
        
        if ($openBraces !== 0) {
            // Couldn't find matching brace
            return $content;
        }
        
        // Extract the paths object
        $pathsSection = substr($content, $pathsOpenBracePos, $closeBracePos - $pathsOpenBracePos);
        
        // Replace static and media URLs in the paths section
        $modifiedPathsSection = preg_replace_callback(
            '/([\'"])(\/(?:static|media)\/[^\'"]+)([\'"])/i',
            function($matches) use ($cdnBaseUrl) {
                $url = $matches[2];
                
                // Determine path to use in CDN URL
                $cdnPath = '';
                if (strpos($url, '/static/') === 0) {
                    $cdnPath = substr($url, 8); // Remove '/static/'
                } elseif (strpos($url, '/media/') === 0) {
                    $cdnPath = substr($url, 7); // Remove '/media/'
                }
                
                if (empty($cdnPath)) {
                    return $matches[0];
                }
                
                $cdnUrl = rtrim($cdnBaseUrl, '/') . '/' . ltrim($cdnPath, '/');
                $this->helper->log("RequireJS path replaced: {$url} -> {$cdnUrl}", 'debug');
                
                return $matches[1] . $cdnUrl . $matches[3];
            },
            $pathsSection
        );
        
        // Replace the original paths section with the modified one
        $content = substr_replace($content, $modifiedPathsSection, $pathsOpenBracePos, $closeBracePos - $pathsOpenBracePos);
        
        return $content;
    }
    
    /**
     * Get list of common RequireJS module paths
     *
     * @return array
     */
    public function getCommonRequireJsModules()
    {
        return [
            'mage/utils/main',
            'mage/utils/misc',
            'mage/utils/template',
            'mage/utils/arrays',
            'mage/utils/strings',
            'mage/utils/objects',
            'mage/utils/compare',
            'jquery/ui-modules/core',
            'jquery/ui-modules/datepicker',
            'jquery/ui-modules/dialog',
            'jquery/ui-modules/widget',
            'Magento_Ui/js/lib/core/events',
            'Magento_Ui/js/lib/core/storage/local',
            'Magento_Ui/js/lib/key-codes',
            'jquery/z-index'
        ];
    }
}