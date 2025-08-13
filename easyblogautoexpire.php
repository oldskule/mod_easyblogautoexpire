<?php
/**
* @package		EasyBlog Auto Expire
* @copyright	Copyright (C) 2024. All rights reserved.
* @license		GNU/GPL, see LICENSE.php
*/
defined('_JEXEC') or die('Unauthorized Access');

class plgSystemEasyBlogAutoExpire extends JPlugin
{
	// Autoload language
	protected $autoloadLanguage = true;

	/**
	 * Tests if EasyBlog exists
	 *
	 * @since	1.0.0
	 * @access	public
	 */
	private function exists()
	{
		static $exists = null;

		if (is_null($exists)) {
			$file = JPATH_ADMINISTRATOR . '/components/com_easyblog/includes/easyblog.php';
			$exists = JFile::exists($file);

			if (!$exists) {
				return false;
			}

			require_once($file);

			if (!EB::isFoundryEnabled()) {
				$exists = false;
			}
		}

		return $exists;
	}

	/**
	 * Hook into EasyBlog's cron system
	 *
	 * @since	1.0.0
	 * @access	public
	 */
	public function onAfterRoute()
	{
		if (!$this->exists()) {
			return;
		}

		$app = JFactory::getApplication();
		$task = $app->input->get('task', '', 'cmd');
		$cron = $app->input->get('cron', false, 'bool');

		// Only run during cron execution
		if (($task == 'cron' || $cron) && $app->isSite()) {
			$this->processExpiredPosts();
		}
	}

	/**
	 * Process posts that match expiration rules
	 *
	 * @since	1.0.0
	 * @access	public
	 */
	public function processExpiredPosts()
	{
		// Get plugin parameters
		$rules = $this->getRules();

		if (empty($rules)) {
			return;
		}

		$db = EB::db();
		$processedCount = 0;

		foreach ($rules as $rule) {
			if (!$rule['enabled']) {
				continue;
			}

			// Calculate the cutoff date
			$cutoffDate = JFactory::getDate('-' . $rule['days'] . ' days')->toSql();

			// Build query to find matching posts
			$query = $db->getQuery(true)
				->select('*')
				->from($db->quoteName('#__easyblog_post'))
				->where($db->quoteName('published') . ' = 1')
				->where($db->quoteName('created') . ' <= ' . $db->quote($cutoffDate));

			// Add title matching condition
			if (!empty($rule['title_search'])) {
				$query->where($db->quoteName('title') . ' LIKE ' . $db->quote('%' . $rule['title_search'] . '%'));
			}

			$db->setQuery($query);
			$posts = $db->loadObjectList();

			foreach ($posts as $postData) {
				$post = EB::post($postData->id);

				if (!$post->id) {
					continue;
				}

				// Process based on action type
				switch ($rule['action']) {
					case 'disable':
						$this->disablePost($post);
						break;

					case 'archive':
						$this->archivePost($post);
						break;

					case 'both':
						$this->archivePost($post);
						$this->disablePost($post);
						break;
				}

				$processedCount++;
			}
		}

		// Log the results
		if ($processedCount > 0) {
			EB::info('EasyBlog Auto Expire processed ' . $processedCount . ' posts.');
		}
	}

	/**
	 * Disable a post
	 *
	 * @since	1.0.0
	 * @access	private
	 */
	private function disablePost($post)
	{
		$options = [
			'isScheduleProcess' => true
		];

		$post->unpublish($options);
	}

	/**
	 * Archive a post
	 *
	 * @since	1.0.0
	 * @access	private
	 */
	private function archivePost($post)
	{
		// Set post state to archived (state = 2)
		$db = EB::db();
		$query = $db->getQuery(true)
			->update($db->quoteName('#__easyblog_post'))
			->set($db->quoteName('state') . ' = 2')
			->where($db->quoteName('id') . ' = ' . $db->quote($post->id));

		$db->setQuery($query);
		$db->execute();
	}

	/**
	 * Parse plugin rules from parameters
	 *
	 * @since	1.0.0
	 * @access	private
	 */
	private function getRules()
	{
		$rules = [];

		// Get up to 10 rules from plugin parameters
		for ($i = 1; $i <= 10; $i++) {
			$enabled = $this->params->get('rule' . $i . '_enabled', 0);
			$titleSearch = $this->params->get('rule' . $i . '_title', '');
			$days = $this->params->get('rule' . $i . '_days', 30);
			$action = $this->params->get('rule' . $i . '_action', 'disable');

			if ($enabled && !empty($titleSearch)) {
				$rules[] = [
					'enabled' => true,
					'title_search' => $titleSearch,
					'days' => (int) $days,
					'action' => $action
				];
			}
		}

		return $rules;
	}
}