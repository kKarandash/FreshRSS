<?php

/**
 * This class handles main actions of FreshRSS.
 */
class FreshRSS_index_Controller extends Minz_ActionController {

	/**
	 * This action only redirect on the default view mode (normal or global)
	 */
	public function indexAction() {
		$prefered_output = FreshRSS_Context::$conf->view_mode;
		Minz_Request::forward(array(
			'c' => 'index',
			'a' => $prefered_output
		));
	}

	/**
	 * This action displays the normal view of FreshRSS.
	 */
	public function normalAction() {
		if (!FreshRSS_Auth::hasAccess() && !Minz_Configuration::allowAnonymous()) {
			Minz_Request::forward(array('c' => 'auth', 'a' => 'login'));
			return;
		}

		try {
			$this->updateContext();
		} catch (FreshRSS_Context_Exception $e) {
			Minz_Error::error(404);
		}

		try {
			$entries = $this->listEntriesByContext();

			if (count($entries) > FreshRSS_Context::$number) {
				// We have more elements for pagination
				$last_entry = array_pop($entries);
				FreshRSS_Context::$next_id = $last_entry->id();
			}

			$this->view->entries = $entries;
		} catch (FreshRSS_EntriesGetter_Exception $e) {
			Minz_Log::notice($e->getMessage());
			Minz_Error::error(404);
		}

		$this->view->categories = FreshRSS_Context::$categories;

		$this->view->rss_title = FreshRSS_Context::$name . ' | ' . Minz_View::title();
		$title = FreshRSS_Context::$name;
		if (FreshRSS_Context::$get_unread > 0) {
			$title = '(' . FreshRSS_Context::$get_unread . ') · ' . $title;
		}
		Minz_View::prependTitle($title . ' · ');
	}

	/**
	 * This action displays the global view of FreshRSS.
	 */
	public function globalAction() {
		if (!FreshRSS_Auth::hasAccess() && !Minz_Configuration::allowAnonymous()) {
			Minz_Request::forward(array('c' => 'auth', 'a' => 'login'));
			return;
		}

		Minz_View::appendScript(Minz_Url::display('/scripts/global_view.js?' . @filemtime(PUBLIC_PATH . '/scripts/global_view.js')));

		try {
			$this->updateContext();
		} catch (FreshRSS_Context_Exception $e) {
			Minz_Error::error(404);
		}

		$this->view->categories = FreshRSS_Context::$categories;

		$this->view->rss_title = FreshRSS_Context::$name . ' | ' . Minz_View::title();
		Minz_View::prependTitle(_t('gen.title.global_view') . ' · ');
	}

	/**
	 * This action displays the RSS feed of FreshRSS.
	 */
	public function rssAction() {
		$token = FreshRSS_Context::$conf->token;
		$token_param = Minz_Request::param('token', '');
		$token_is_ok = ($token != '' && $token === $token_param);

		// Check if user has access.
		if (!FreshRSS_Auth::hasAccess() &&
				!Minz_Configuration::allowAnonymous() &&
				!$token_is_ok) {
			Minz_Error::error(403);
		}

		try {
			$this->updateContext();
		} catch (FreshRSS_Context_Exception $e) {
			Minz_Error::error(404);
		}

		try {
			$this->view->entries = $this->listEntriesByContext();
		} catch (FreshRSS_EntriesGetter_Exception $e) {
			Minz_Log::notice($e->getMessage());
			Minz_Error::error(404);
		}

		// No layout for RSS output.
		$this->view->rss_title = FreshRSS_Context::$name . ' | ' . Minz_View::title();
		$this->view->_useLayout(false);
		header('Content-Type: application/rss+xml; charset=utf-8');
	}

	/**
	 * This action updates the Context object by using request parameters.
	 *
	 * Parameters are:
	 *   - state (default: conf->default_view)
	 *   - search (default: empty string)
	 *   - order (default: conf->sort_order)
	 *   - nb (default: conf->posts_per_page)
	 *   - next (default: empty string)
	 */
	private function updateContext() {
		FreshRSS_Context::_get(Minz_Request::param('get', 'a'));

		// TODO: change default_view by default_state.
		FreshRSS_Context::$state = Minz_Request::param(
			'state', FreshRSS_Context::$conf->default_view
		);
		$state_forced_by_user = Minz_Request::param('state', false) !== false;
		if (FreshRSS_Context::isStateEnabled(FreshRSS_Entry::STATE_NOT_READ) &&
				FreshRSS_Context::$get_unread <= 0 &&
				!$state_forced_by_user) {
			FreshRSS_Context::$state |= FreshRSS_Entry::STATE_READ;
		}

		FreshRSS_Context::$search = Minz_Request::param('search', '');
		FreshRSS_Context::$order = Minz_Request::param(
			'order', FreshRSS_Context::$conf->sort_order
		);
		FreshRSS_Context::$number = Minz_Request::param(
			'nb', FreshRSS_Context::$conf->posts_per_page
		);
		FreshRSS_Context::$first_id = Minz_Request::param('next', '');
	}

	/**
	 * This method returns a list of entries based on the Context object.
	 */
	private function listEntriesByContext() {
		$entryDAO = FreshRSS_Factory::createEntryDao();

		$get = FreshRSS_Context::currentGet(true);
		if (count($get) > 1) {
			$type = $get[0];
			$id = $get[1];
		} else {
			$type = $get;
			$id = '';
		}

		return $entryDAO->listWhere(
			$type, $id, FreshRSS_Context::$state, FreshRSS_Context::$order,
			FreshRSS_Context::$number + 1, FreshRSS_Context::$first_id,
			FreshRSS_Context::$search
		);
	}

	/**
	 * This action displays the about page of FreshRSS.
	 */
	public function aboutAction() {
		Minz_View::prependTitle(_t('about') . ' · ');
	}

	/**
	 * This action displays logs of FreshRSS for the current user.
	 */
	public function logsAction() {
		if (!FreshRSS_Auth::hasAccess()) {
			Minz_Error::error(403);
		}

		Minz_View::prependTitle(_t('logs') . ' · ');

		if (Minz_Request::isPost()) {
			FreshRSS_LogDAO::truncate();
		}

		$logs = FreshRSS_LogDAO::lines();	//TODO: ask only the necessary lines

		//gestion pagination
		$page = Minz_Request::param('page', 1);
		$this->view->logsPaginator = new Minz_Paginator($logs);
		$this->view->logsPaginator->_nbItemsPerPage(50);
		$this->view->logsPaginator->_currentPage($page);
	}
}
