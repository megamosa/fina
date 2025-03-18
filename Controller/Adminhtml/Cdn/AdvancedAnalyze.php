<?php
namespace MagoArab\CdnIntegration\Controller\Adminhtml\Cdn;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use MagoArab\CdnIntegration\Helper\Data as Helper;
use MagoArab\CdnIntegration\Model\AdvancedUrlAnalyzer;

class AdvancedAnalyze extends Action
{
    /**
     * Authorization level
     */
    const ADMIN_RESOURCE = 'MagoArab_CdnIntegration::config';

    /**
     * @var JsonFactory
     */
    protected $resultJsonFactory;

    /**
     * @var Helper
     */
    protected $helper;
    
    /**
     * @var AdvancedUrlAnalyzer
     */
    protected $urlAnalyzer;
    
    /**
     * @var array
     */
    protected $progressData = [
        'status' => 'Initializing',
        'percent' => 0,
        'detail' => 'Starting analysis...'
    ];

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param Helper $helper
     * @param AdvancedUrlAnalyzer $urlAnalyzer
     */
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        Helper $helper,
        AdvancedUrlAnalyzer $urlAnalyzer
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->helper = $helper;
        $this->urlAnalyzer = $urlAnalyzer;
    }

    /**
     * Advanced analyze URLs
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $resultJson = $this->resultJsonFactory->create();
        
        try {
            if (!$this->helper->isEnabled()) {
                return $resultJson->setData([
                    'success' => false,
                    'message' => __('CDN Integration is disabled.')
                ]);
            }
            
            // Check if this is a progress check request
            if ($this->getRequest()->getParam('check_progress')) {
                // Return current progress data
                return $resultJson->setData([
                    'success' => true,
                    'progress' => $this->getProgressFromSession()
                ]);
            }
            
            $storeUrl = $this->getRequest()->getParam('store_url');
            $maxPages = (int)$this->getRequest()->getParam('max_pages', 5);
            
            if (empty($storeUrl)) {
                return $resultJson->setData([
                    'success' => false,
                    'message' => __('Store URL is required.')
                ]);
            }
            
            $this->helper->log("Starting advanced URL analysis for: {$storeUrl}", 'info');
            
            // Set up progress callback
            $this->urlAnalyzer->setProgressCallback([$this, 'updateProgress']);
            
            // Reset progress in session
            $this->saveProgressToSession([
                'status' => 'Starting',
                'percent' => 0,
                'detail' => 'Initializing analysis...'
            ]);
            
            // Execute the advanced analysis
            $urls = $this->urlAnalyzer->analyze($storeUrl, $maxPages);
            
            if (empty($urls)) {
                $this->helper->log("No URLs found in advanced analysis", 'warning');
                
                // Update final progress
                $this->saveProgressToSession([
                    'status' => 'Complete',
                    'percent' => 100,
                    'detail' => 'No suitable URLs found to analyze.'
                ]);
                
                return $resultJson->setData([
                    'success' => false,
                    'message' => __('No suitable URLs found to analyze.')
                ]);
            }
            
            $this->helper->log("Advanced analysis complete, found " . count($urls) . " URLs", 'info');
            
            // Update final progress
            $this->saveProgressToSession([
                'status' => 'Complete',
                'percent' => 100,
                'detail' => __('Analysis complete! Found %1 unique static and media URLs.', count($urls))
            ]);
            
            $this->messageManager->addSuccessMessage(
                __('Advanced analysis found %1 unique static and media URLs.', count($urls))
            );
            
            // Prepare URLs stats by type for UI
            $stats = $this->categorizeUrls($urls);
            
            return $resultJson->setData([
                'success' => true,
                'urls' => $urls,
                'stats' => $stats,
                'message' => __('URL analysis completed with %1 URLs found.', count($urls)),
                'progress' => $this->getProgressFromSession()
            ]);
        } catch (\Exception $e) {
            $this->helper->log('Error in AdvancedAnalyze::execute: ' . $e->getMessage(), 'error');
            
            // Update error progress
            $this->saveProgressToSession([
                'status' => 'Error',
                'percent' => 100,
                'detail' => $e->getMessage()
            ]);
            
            $this->messageManager->addExceptionMessage($e, __('An error occurred during advanced URL analysis.'));
            
            return $resultJson->setData([
                'success' => false,
                'message' => $e->getMessage(),
                'progress' => $this->getProgressFromSession()
            ]);
        }
    }
    
    /**
     * Update progress callback
     *
     * @param string $status
     * @param int $percent
     * @param string $detail
     * @return void
     */
    public function updateProgress($status, $percent, $detail = '')
    {
        $progress = [
            'status' => $status,
            'percent' => $percent,
            'detail' => $detail
        ];
        
        $this->saveProgressToSession($progress);
    }
    
    /**
     * Save progress data to session
     *
     * @param array $progress
     * @return void
     */
    protected function saveProgressToSession($progress)
    {
        $this->_session->setAdvancedAnalyzeProgress($progress);
    }
    
    /**
     * Get progress data from session
     *
     * @return array
     */
    protected function getProgressFromSession()
    {
        $progress = $this->_session->getAdvancedAnalyzeProgress();
        
        if (!$progress) {
            $progress = [
                'status' => 'Initializing',
                'percent' => 0,
                'detail' => 'Starting analysis...'
            ];
        }
        
        return $progress;
    }
    
    /**
     * Categorize URLs by type for statistics
     *
     * @param array $urls
     * @return array
     */
    protected function categorizeUrls($urls)
    {
        $stats = [
            'js' => 0,
            'css' => 0,
            'images' => 0,
            'fonts' => 0,
            'other' => 0
        ];
        
        foreach ($urls as $url) {
            $extension = pathinfo($url, PATHINFO_EXTENSION);
            
            if ($extension === 'js') {
                $stats['js']++;
            } elseif ($extension === 'css') {
                $stats['css']++;
            } elseif (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'])) {
                $stats['images']++;
            } elseif (in_array($extension, ['woff', 'woff2', 'ttf', 'eot', 'otf'])) {
                $stats['fonts']++;
            } else {
                $stats['other']++;
            }
        }
        
        $stats['total'] = count($urls);
        
        return $stats;
    }
}