<?php
namespace MagoArab\CdnIntegration\Model;

use MagoArab\CdnIntegration\Helper\Data as Helper;
use MagoArab\CdnIntegration\Model\Github\Api as GithubApi;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Driver\File as FileDriver;

class UrlValidator
{
    /**
     * @var Helper
     */
    protected $helper;
    
    /**
     * @var GithubApi
     */
    protected $githubApi;
    
    /**
     * @var Filesystem
     */
    protected $filesystem;
    
    /**
     * @var FileDriver
     */
    protected $fileDriver;

    /**
     * @param Helper $helper
     * @param GithubApi $githubApi
     * @param Filesystem $filesystem
     * @param FileDriver $fileDriver
     */
    public function __construct(
        Helper $helper,
        GithubApi $githubApi,
        Filesystem $filesystem,
        FileDriver $fileDriver
    ) {
        $this->helper = $helper;
        $this->githubApi = $githubApi;
        $this->filesystem = $filesystem;
        $this->fileDriver = $fileDriver;
    }
    
    /**
     * Validate and auto-upload custom URLs
     *
     * @return array
     */
    public function validateAndUploadCustomUrls()
    {
        $customUrls = $this->helper->getCustomUrls();
        if (empty($customUrls)) {
            return ['success' => true, 'message' => 'No custom URLs defined.', 'details' => []];
        }
        
        $staticDir = $this->filesystem->getDirectoryRead(DirectoryList::STATIC_VIEW)->getAbsolutePath();
        $mediaDir = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA)->getAbsolutePath();
        
        $results = [
            'success' => true,
            'processed' => 0,
            'uploaded' => 0,
            'failed' => 0,
            'skipped' => 0,
            'already_on_github' => 0,
            'details' => []
        ];
        
        foreach ($customUrls as $url) {
            $results['processed']++;
            
            // Skip empty URLs
            if (empty($url)) {
                continue;
            }
            
            // Normalize URL (remove domain if present)
            $normalizedUrl = $url;
            if (strpos($url, 'http') === 0) {
                $parsedUrl = parse_url($url);
                if (isset($parsedUrl['path'])) {
                    $normalizedUrl = $parsedUrl['path'];
                }
            }
            
            // Skip if not a static or media URL
            if (strpos($normalizedUrl, '/static/') !== 0 && strpos($normalizedUrl, '/media/') !== 0) {
                $results['skipped']++;
                $results['details'][] = [
                    'url' => $url,
                    'status' => 'skipped',
                    'message' => 'Not a static or media URL'
                ];
                continue;
            }
            
            // Determine local file path and remote path
            $localPath = '';
            $remotePath = '';
            
            if (strpos($normalizedUrl, '/static/') === 0) {
                $path = substr($normalizedUrl, 8); // Remove '/static/'
                $localPath = $staticDir . $path;
                $remotePath = $path;
            } elseif (strpos($normalizedUrl, '/media/') === 0) {
                $path = substr($normalizedUrl, 7); // Remove '/media/'
                $localPath = $mediaDir . $path;
                $remotePath = $path;
            }
            
            // Check if the file exists locally
            if (!$this->fileDriver->isExists($localPath)) {
                $results['failed']++;
                $results['details'][] = [
                    'url' => $url,
                    'status' => 'failed',
                    'message' => 'File not found locally: ' . $localPath
                ];
                continue;
            }
            
            // Check if the file already exists on GitHub
            $existingFile = $this->githubApi->getContents($remotePath);
            
            if ($existingFile && isset($existingFile['sha'])) {
                $results['already_on_github']++;
                $results['details'][] = [
                    'url' => $url,
                    'status' => 'exists',
                    'message' => 'File already exists on GitHub'
                ];
                continue;
            }
            
            // Upload the file to GitHub
            $success = $this->githubApi->uploadFile($localPath, $remotePath);
            
            if ($success) {
                $results['uploaded']++;
                $results['details'][] = [
                    'url' => $url,
                    'status' => 'uploaded',
                    'message' => 'Successfully uploaded to GitHub'
                ];
            } else {
                $results['failed']++;
                $results['details'][] = [
                    'url' => $url,
                    'status' => 'failed',
                    'message' => 'Failed to upload to GitHub'
                ];
            }
        }
        
        $results['message'] = sprintf(
            'Processed %d URLs: %d uploaded, %d already on GitHub, %d failed, %d skipped',
            $results['processed'],
            $results['uploaded'],
            $results['already_on_github'],
            $results['failed'],
            $results['skipped']
        );
        
        return $results;
    }
    
    /**
     * Check if a specific URL exists on GitHub
     * 
     * @param string $url
     * @return bool
     */
    public function checkUrlExistsOnGithub($url)
    {
        // Normalize URL
        $normalizedUrl = $url;
        if (strpos($url, 'http') === 0) {
            $parsedUrl = parse_url($url);
            if (isset($parsedUrl['path'])) {
                $normalizedUrl = $parsedUrl['path'];
            }
        }
        
        // Determine remote path
        $remotePath = '';
        
        if (strpos($normalizedUrl, '/static/') === 0) {
            $remotePath = substr($normalizedUrl, 8); // Remove '/static/'
        } elseif (strpos($normalizedUrl, '/media/') === 0) {
            $remotePath = substr($normalizedUrl, 7); // Remove '/media/'
        } else {
            return false; // Not a static or media URL
        }
        
        // Check if the file exists on GitHub
        $existingFile = $this->githubApi->getContents($remotePath);
        
        return ($existingFile && isset($existingFile['sha']));
    }
    
/**
 * Get local file path for a URL
 * 
 * @param string $url
 * @return string
 */
public function getLocalPathForUrl($url)
{
    // Normalize URL
    $normalizedUrl = $url;
    if (strpos($url, 'http') === 0) {
        $parsedUrl = parse_url($url);
        if (isset($parsedUrl['path'])) {
            $normalizedUrl = $parsedUrl['path'];
        }
    }
    
    // Ensure URL starts with a slash
    if (strpos($normalizedUrl, '/') !== 0) {
        $normalizedUrl = '/' . $normalizedUrl;
    }
    
    // Skip if not a static or media URL
    if (strpos($normalizedUrl, '/static/') !== 0 && strpos($normalizedUrl, '/media/') !== 0) {
        return '';
    }
    
    try {
        $staticDir = $this->filesystem->getDirectoryRead(DirectoryList::STATIC_VIEW)->getAbsolutePath();
        $mediaDir = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA)->getAbsolutePath();
        
        // Determine local file path
        if (strpos($normalizedUrl, '/static/') === 0) {
            $path = substr($normalizedUrl, 8); // Remove '/static/'
            return $staticDir . $path;
        } elseif (strpos($normalizedUrl, '/media/') === 0) {
            $path = substr($normalizedUrl, 7); // Remove '/media/'
            return $mediaDir . $path;
        }
    } catch (\Exception $e) {
        $this->helper->log('Error getting file path for URL: ' . $e->getMessage(), 'error');
        return '';
    }
    
    return '';
}
    
    /**
     * Upload a file to GitHub
     * 
     * @param string $url The original URL
     * @param string $localPath The local file path
     * @return bool
     */
    public function uploadFileToGithub($url, $localPath)
    {
        try {
            // Normalize URL and get remote path
            $normalizedUrl = $url;
            if (strpos($url, 'http') === 0) {
                $parsedUrl = parse_url($url);
                if (isset($parsedUrl['path'])) {
                    $normalizedUrl = $parsedUrl['path'];
                }
            }
            
            $remotePath = '';
            if (strpos($normalizedUrl, '/static/') === 0) {
                $remotePath = substr($normalizedUrl, 8); // Remove '/static/'
            } elseif (strpos($normalizedUrl, '/media/') === 0) {
                $remotePath = substr($normalizedUrl, 7); // Remove '/media/'
            } else {
                return false; // Not a static or media URL
            }
            
            // Check if file exists locally
            if (!file_exists($localPath)) {
                $this->helper->log('File does not exist locally: ' . $localPath, 'error');
                return false;
            }
            
            // Upload file to GitHub
            $success = $this->githubApi->uploadFile($localPath, $remotePath);
            
            if ($success) {
                $this->helper->log('Successfully uploaded file to GitHub: ' . $url, 'info');
                return true;
            } else {
                $this->helper->log('Failed to upload file to GitHub: ' . $url, 'error');
                return false;
            }
        } catch (\Exception $e) {
            $this->helper->log('Error uploading file to GitHub: ' . $e->getMessage(), 'error');
            return false;
        }
    }
}