<?php

use Slim\App;
use Slim\Http\Request;
use Slim\Http\Response;

/**
	* @var App $g_app
	*/
$g_app->group('/dashboard', function () use ($g_app) {
		$g_app->get('', 'rest_dashboard_get');
});

/**
	* A method that does the work to handle getting a set of localized strings via REST API.
	*
	* @param Request $p_request The request.
	* @param Response $p_response The response.
	* @param array $p_args Arguments
	* @return Response The augmented response.
	*/
function rest_dashboard_get(Request $p_request, Response $p_response, array $p_args): Response
{
		$p_filter = summary_get_filter();
		
		$t_project_id = helper_get_current_project();
		$t_user_id = auth_get_current_user_id();
		$t_specific_where = helper_project_specific_where($t_project_id, $t_user_id);
 	$t_resolved_status_threshold = config_get('bug_resolved_status_threshold');

		$t_query = new DBQuery();
		$t_sql = 'SELECT handler_id, count(*) as count FROM {bug} WHERE ' . $t_specific_where
				. ' AND handler_id <> :nouser AND status < :status_resolved';
		if (!empty($p_filter)) {
				$t_subquery = filter_cache_subquery($p_filter);
				$t_sql .= ' AND {bug}.id IN :filter';
				$t_query->bind('filter', $t_subquery);
		}
		$t_sql .= ' GROUP BY handler_id ORDER BY count DESC';
		$t_query->sql($t_sql);
		$t_query->bind(array(
				'nouser' => NO_USER,
				'status_resolved' => (int)$t_resolved_status_threshold,
		));

		$t_handler_array = array();
		$t_handler_ids = array();

		while ($t_row = $t_query->fetch()) {
				$t_handler_array[$t_row['handler_id']] = (int)$t_row['count'];
				$t_handler_ids[] = $t_row['handler_id'];
		}

		if (count($t_handler_array) == 0) {
				return $p_response->withStatus(HTTP_STATUS_SUCCESS)->withJson([
						'data' => $t_specific_where
				]);
		}

		user_cache_array_rows($t_handler_ids);

		foreach ($t_handler_array as $t_handler_id => $t_count) {
				$t_metrics[user_get_name($t_handler_id)] = $t_count;
		}

		arsort($t_metrics);
		
		return $p_response->withStatus(HTTP_STATUS_SUCCESS)->withJson([
				'data' => $t_specific_where
		]);
}