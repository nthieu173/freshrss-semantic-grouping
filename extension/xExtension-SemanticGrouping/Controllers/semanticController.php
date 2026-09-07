<?php
declare(strict_types=1);

final class FreshExtension_semantic_Controller extends FreshRSS_ActionController {
	#[\Override]
	public function firstAction(): void {
		if (!FreshRSS_Auth::hasAccess()) {
			Minz_Error::error(403);
			return;
		}
		FreshRSS_View::prependTitle('Semantic Groups · ');
	}

	public function indexAction(): void {
		$page = max(1, Minz_Request::paramInt('page'));
		try {
			$repository = new SemanticGrouping_GroupRepository();
			$this->view->semanticGroups = $repository->groups($page);
			$this->view->semanticStatus = $repository->status();
			$this->view->semanticError = '';
		} catch (Throwable $error) {
			Minz_Log::warning('Semantic groups page unavailable: ' . $error->getMessage());
			$this->view->semanticGroups = ['groups' => [], 'page' => 1, 'pages' => 1, 'total' => 0];
			$this->view->semanticStatus = ['available' => false];
			$this->view->semanticError = 'Semantic groups are temporarily unavailable. FreshRSS articles are unaffected.';
		}
	}
}

