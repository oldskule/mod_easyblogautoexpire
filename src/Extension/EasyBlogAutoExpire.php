<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  System.EasyBlogAutoExpire
 *
 * @copyright   Copyright (C) 2024. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\System\EasyBlogAutoExpire\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;

// Import JFile for compatibility
jimport('joomla.filesystem.file');
use Joomla\CMS\Filesystem\File;

/**
 * EasyBlog Auto Expire Plugin
 *
 * @since  1.0.0
 */
final class EasyBlogAutoExpire extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;

    /**
     * Load the language file on instantiation.
     *
     * @var    boolean
     * @since  1.0.0
     */
    protected $autoloadLanguage = true;

    /**
     * Application object
     *
     * @var    CMSApplication
     * @since  1.0.0
     */
    protected $app;

    /**
     * Returns an array of events this subscriber will listen to.
     *
     * @return  array
     *
     * @since   1.0.0
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterRoute' => 'onAfterRoute',
        ];
    }

    /**
     * Tests if EasyBlog exists and is properly configured
     *
     * @return  boolean
     *
     * @since   1.0.0
     */
    private function exists(): bool
    {
        static $exists = null;

        if (is_null($exists)) {
            $file = JPATH_ADMINISTRATOR . '/components/com_easyblog/includes/easyblog.php';
            
            // Use JFile for compatibility
            if (!class_exists('JFile')) {
                jimport('joomla.filesystem.file');
            }
            
            $exists = \JFile::exists($file);

            if (!$exists) {
                return false;
            }

            // Load EasyBlog framework
            require_once $file;

            // Check if EB class exists and Foundry is enabled - use global namespace
            if (!class_exists('\EB')) {
                $exists = false;
            } else {
                try {
                    $exists = \EB::isFoundryEnabled();
                } catch (Exception $e) {
                    $exists = false;
                }
            }
        }

        return $exists;
    }

    /**
     * Hook into EasyBlog's cron system
     *
     * @param   Event  $event  The event object
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function onAfterRoute(Event $event): void
    {
        // Debug: Always log that the plugin is being called
        $this->debugLog('DEBUG: onAfterRoute called');
        
        if (!$this->exists()) {
            $this->debugLog('DEBUG: EasyBlog not found or not enabled');
            return;
        }
        
        $this->debugLog('DEBUG: EasyBlog exists, checking cron parameters');

        $input = $this->app->getInput();
        $task = $input->getCmd('task', '');
        $cron = $input->getBool('cron', false);
        $client = $this->app->isClient('site') ? 'site' : 'admin';
        
        $this->debugLog("DEBUG: task='$task', cron='$cron', client='$client'");

        // Only run during cron execution
        if (($task === 'cron' || $cron) && $this->app->isClient('site')) {
            $this->debugLog('DEBUG: Cron conditions met, processing posts');
            $this->processExpiredPosts();
        } else {
            $this->debugLog('DEBUG: Cron conditions NOT met, skipping');
        }
    }

    /**
     * Process posts that match expiration rules
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function processExpiredPosts(): void
    {
        try {
            $this->debugLog('DEBUG: processExpiredPosts started');
            
            // Get plugin parameters
            $rules = $this->getRules();
            $developmentMode = (bool) $this->params->get('development_mode', 0);
            
            $this->debugLog("DEBUG: Found " . count($rules) . " rules, development mode: " . ($developmentMode ? 'YES' : 'NO'));
    
            if (empty($rules)) {
                $this->debugLog('DEBUG: No rules configured, exiting');
                return;
            }
    
            $this->debugLog('DEBUG: About to get database connection');
            try {
                // Try Joomla's native database connection
                $db = Factory::getDbo();
                $this->debugLog('DEBUG: Database connection obtained via Factory::getDbo()');
            } catch (Exception $e) {
                $this->debugLog('DEBUG: Factory::getDbo() failed: ' . $e->getMessage());
                try {
                    // Fallback to parent method
                    $db = $this->getDatabase();
                    $this->debugLog('DEBUG: Database connection obtained via parent getDatabase()');
                } catch (Exception $e2) {
                    $this->debugLog('DEBUG: Parent getDatabase() also failed: ' . $e2->getMessage());
                    return;
                }
            }
    
            $processedCount = 0;
            $processedPosts = [];
    
            $this->debugLog('DEBUG: About to start foreach loop with ' . count($rules) . ' rules');
    
            foreach ($rules as $ruleIndex => $rule) {
                $this->debugLog("DEBUG: Processing rule {$ruleIndex}: enabled=" . ($rule['enabled'] ? 'true' : 'false') . ", title_search='{$rule['title_search']}'");
                
                if (!$rule['enabled']) {
                    $this->debugLog("DEBUG: Rule {$ruleIndex} is disabled, skipping");
                    continue;
                }
                
                $this->debugLog("DEBUG: Rule {$ruleIndex} is enabled, proceeding with processing");
                
                // Calculate the cutoff date
                $cutoffDate = Factory::getDate('-' . $rule['days'] . ' days')->toSql();
                $this->debugLog("DEBUG: Rule " . ($ruleIndex + 1) . " - Search: '{$rule['title_search']}', Days: {$rule['days']}, Cutoff: {$cutoffDate}");
    
                // Build query to find matching posts
                $query = $db->getQuery(true)
                    ->select($db->quoteName(['id', 'title', 'created', 'published', 'state']))
                    ->from($db->quoteName('#__easyblog_post'))
                    ->where($db->quoteName('published') . ' = 1')
                    ->where($db->quoteName('created') . ' <= ' . $db->quote($cutoffDate));
    
                // Add title matching condition with proper escaping
                if (!empty($rule['title_search'])) {
                    $searchTerm = '%' . $db->escape($rule['title_search'], true) . '%';
                    $query->where($db->quoteName('title') . ' LIKE ' . $db->quote($searchTerm));
                    $this->debugLog("DEBUG: Search term: {$searchTerm}");
                }
    
                $this->debugLog("DEBUG: SQL Query: " . (string) $query);
                $db->setQuery($query);
                $posts = $db->loadObjectList();
                $this->debugLog("DEBUG: Found " . count($posts) . " matching posts");
    
                foreach ($posts as $postData) {
                    $this->debugLog("DEBUG: Post found - ID: {$postData->id}, Title: '{$postData->title}', Created: {$postData->created}");
                    if (!$this->validatePost($postData)) {
                        continue;
                    }
    
                    $post = \EB::post($postData->id);
    
                    if (!$post || !$post->id) {
                        continue;
                    }
    
                    // Process based on action type
                    $actionTaken = '';
                    if ($developmentMode) {
                        // Development mode - only log what would happen
                        switch ($rule['action']) {
                            case 'disable':
                                $actionTaken = 'would disable';
                                break;
                            case 'archive':
                                $actionTaken = 'would archive';
                                break;
                            case 'both':
                                $actionTaken = 'would archive and disable';
                                break;
                        }
                    } else {
                        // Production mode - actually perform actions
                        switch ($rule['action']) {
                            case 'disable':
                                $this->disablePost($post);
                                $actionTaken = 'disabled';
                                break;
    
                            case 'archive':
                                $this->archivePost($post);
                                $actionTaken = 'archived';
                                break;
    
                            case 'both':
                                $this->archivePost($post);
                                $this->disablePost($post);
                                $actionTaken = 'archived and disabled';
                                break;
                        }
                    }
    
                    // Log individual post processing
                    $processedPosts[] = [
                        'id' => $post->id,
                        'title' => $postData->title,
                        'action' => $actionTaken,
                        'rule' => $ruleIndex + 1,
                        'search_term' => $rule['title_search'],
                        'days' => $rule['days'],
                        'development_mode' => $developmentMode
                    ];
    
                    $processedCount++;
                }
            }
    
            // Log the results to EasyBlog diagnostics file
            if ($processedCount > 0) {
                $this->logToEasyBlogDiagnostics($processedPosts, $processedCount, $developmentMode);
                
                // Use different log message for development mode
                $logMessage = $developmentMode 
                    ? Text::sprintf('PLG_SYSTEM_EASYBLOGAUTOEXPIRE_DEVELOPMENT_PROCESSED_POSTS', $processedCount)
                    : Text::sprintf('PLG_SYSTEM_EASYBLOGAUTOEXPIRE_PROCESSED_POSTS', $processedCount);
                    
                Log::add(
                    $logMessage,
                    Log::INFO,
                    'plg_system_easyblogautoexpire'
                );
            }
        } catch (\Exception $e) {
            Log::add(
                Text::sprintf('PLG_SYSTEM_EASYBLOGAUTOEXPIRE_ERROR', $e->getMessage()),
                Log::ERROR,
                'plg_system_easyblogautoexpire'
            );
        }
    }

    /**
     * Log processed posts to EasyBlog diagnostics file
     *
     * @param   array    $processedPosts   Array of processed post data
     * @param   integer  $totalCount       Total number of processed posts
     * @param   boolean  $developmentMode  Whether in development mode
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private function logToEasyBlogDiagnostics(array $processedPosts, int $totalCount, bool $developmentMode): void
    {
        try {
            $logFile = JPATH_ADMINISTRATOR . '/logs/com_easyblog_diagnostics.php';
            $timestamp = Factory::getDate()->format('Y-m-d H:i:s');
            $modeText = $developmentMode ? ' (DEVELOPMENT MODE)' : '';
            
            // Create log entry
            $logEntry = "\n" . str_repeat('=', 80) . "\n";
            $logEntry .= "[{$timestamp}] EasyBlog Auto Expire Plugin{$modeText} - Processed {$totalCount} posts\n";
            $logEntry .= str_repeat('-', 80) . "\n";
            
            foreach ($processedPosts as $post) {
                $logEntry .= sprintf(
                    "Post ID: %d | Title: %s | Action: %s | Rule: %d | Search: '%s' | Days: %d%s\n",
                    $post['id'],
                    substr($post['title'], 0, 50) . (strlen($post['title']) > 50 ? '...' : ''),
                    $post['action'],
                    $post['rule'],
                    $post['search_term'],
                    $post['days'],
                    $developmentMode ? ' [TEST ONLY]' : ''
                );
            }
            
            if ($developmentMode) {
                $logEntry .= "\n*** DEVELOPMENT MODE ACTIVE - NO ACTUAL CHANGES MADE ***\n";
            }
            
            $logEntry .= str_repeat('=', 80) . "\n";
            
            // Check if log file exists, if not create it with PHP header
            if (!File::exists($logFile)) {
                $phpHeader = "<?php defined('_JEXEC') or die; ?>\n";
                $phpHeader .= "EasyBlog Diagnostics Log File\n";
                $phpHeader .= "Generated: " . $timestamp . "\n";
                File::write($logFile, $phpHeader);
            }
            
            // Append log entry
            file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
            
        } catch (\Exception $e) {
            // Fallback to standard Joomla logging if file write fails
            Log::add(
                'Failed to write to EasyBlog diagnostics log: ' . $e->getMessage(),
                Log::WARNING,
                'plg_system_easyblogautoexpire'
            );
        }
    }

    /**
     * Validate post data
     *
     * @param   object  $postData  The post data object
     *
     * @return  boolean
     *
     * @since   1.0.0
     */
    private function validatePost($postData): bool
    {
        return isset($postData->id) && 
               is_numeric($postData->id) && 
               $postData->id > 0 &&
               isset($postData->published) &&
               $postData->published == 1;
    }

    /**
     * Disable a post (unpublish)
     *
     * @param   object  $post  The EasyBlog post object
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private function disablePost($post): void
    {
        try {
            $options = [
                'isScheduleProcess' => true
            ];

            $post->unpublish($options);
        } catch (\Exception $e) {
            Log::add(
                Text::sprintf('PLG_SYSTEM_EASYBLOGAUTOEXPIRE_DISABLE_ERROR', $post->id, $e->getMessage()),
                Log::ERROR,
                'plg_system_easyblogautoexpire'
            );
        }
    }

    /**
     * Archive a post (set state to 2)
     *
     * @param   object  $post  The EasyBlog post object
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private function archivePost($post): void
    {
        try {
            $db = $this->getDatabase();
            $query = $db->getQuery(true)
                ->update($db->quoteName('#__easyblog_post'))
                ->set($db->quoteName('state') . ' = 2')
                ->where($db->quoteName('id') . ' = ' . (int) $post->id);

            $db->setQuery($query);
            $db->execute();
        } catch (\Exception $e) {
            Log::add(
                Text::sprintf('PLG_SYSTEM_EASYBLOGAUTOEXPIRE_ARCHIVE_ERROR', $post->id, $e->getMessage()),
                Log::ERROR,
                'plg_system_easyblogautoexpire'
            );
        }
    }

    /**
     * Parse plugin rules from parameters
     *
     * @return  array
     *
     * @since   1.0.0
     */
    private function getRules(): array
    {
        $rules = [];
        $this->debugLog('DEBUG: getRules() started');
    
        // Get up to 9 rules from plugin parameters
        for ($i = 1; $i <= 9; $i++) {
            $enabled = (bool) $this->params->get('rule' . $i . '_enabled', 0);
            $titleSearch = $this->params->get('rule' . $i . '_title', '', 'string');
            $days = (int) $this->params->get('rule' . $i . '_days', 30);
            $action = $this->params->get('rule' . $i . '_action', 'disable', 'cmd');
            
            $this->debugLog("DEBUG: Rule {$i} - enabled: {$enabled}, title: '{$titleSearch}', days: {$days}, action: {$action}");
    
            // Validate inputs
            if ($enabled && !empty($titleSearch) && $days > 0 && $days <= 3650) {
                // Sanitize title search
                $titleSearch = trim($titleSearch);
                
                // Validate action
                if (!in_array($action, ['disable', 'archive', 'both'])) {
                    $action = 'disable';
                }
    
                $rules[] = [
                    'enabled' => true,
                    'title_search' => $titleSearch,
                    'days' => $days,
                    'action' => $action
                ];
                
                $this->debugLog("DEBUG: Rule {$i} added to rules array");
            } else {
                $this->debugLog("DEBUG: Rule {$i} skipped - validation failed");
            }
        }
        
        $this->debugLog('DEBUG: getRules() completed, returning ' . count($rules) . ' rules');
        return $rules;
    }

    /**
     * Debug logging method for development
     *
     * @param   string  $message  Debug message to log
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private function debugLog(string $message): void
    {
        try {
            $logFile = JPATH_ADMINISTRATOR . '/logs/com_easyblog_diagnostics.php';
            $timestamp = Factory::getDate()->format('Y-m-d H:i:s');
            
            // Check if log file exists, if not create it with PHP header
            if (!File::exists($logFile)) {
                $phpHeader = "<?php defined('_JEXEC') or die; ?>\n";
                $phpHeader .= "EasyBlog Diagnostics Log File\n";
                $phpHeader .= "Generated: " . $timestamp . "\n";
                File::write($logFile, $phpHeader);
            }
            
            // Append debug message
            $debugEntry = "[{$timestamp}] {$message}\n";
            file_put_contents($logFile, $debugEntry, FILE_APPEND | LOCK_EX);
            
        } catch (\Exception $e) {
            // Fallback to standard Joomla logging if file write fails
            Log::add(
                'Debug log failed: ' . $e->getMessage(),
                Log::WARNING,
                'plg_system_easyblogautoexpire'
            );
        }
    }
}