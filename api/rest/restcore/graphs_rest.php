<?php

use Slim\App;
use Slim\Http\Request;
use Slim\Http\Response;

/**
 * @var App $g_app
 */
$g_app->group('/', function () use ($g_app) {
    $g_app->get('dashboard', 'rest_dashboard_get');
    $g_app->get('developer_resolved_summary', 'rest_developer_resolved_summary_get');
    $g_app->get('developer_open_summary', 'rest_developer_open_summary_get');
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

    $data = [
        'developer_resolved_summary' => developer_resolved_summary($p_filter),
        'developer_open_summary' => developer_open_summary($p_filter),
        'bug_status_summary' => bug_status_summary($p_filter),
    ];

    return $p_response->withStatus(HTTP_STATUS_SUCCESS)->withJson([
        'data' => $data
    ]);
}

function rest_developer_resolved_summary_get(Request $p_request, Response $p_response, array $p_args): Response
{
    $p_filter = summary_get_filter();

    return $p_response->withStatus(HTTP_STATUS_SUCCESS)->withJson(developer_resolved_summary($p_filter));
}

function rest_developer_open_summary_get(Request $p_request, Response $p_response, array $p_args): Response
{
    $p_filter = summary_get_filter();

    return $p_response->withStatus(HTTP_STATUS_SUCCESS)->withJson(developer_open_summary($p_filter));
}

function developer_resolved_summary(array $p_filter = null): array
{
    $t_project_id = helper_get_current_project();
    $t_user_id = auth_get_current_user_id();
    $t_specific_where = helper_project_specific_where($t_project_id, $t_user_id);
    $t_resolved_status_threshold = config_get('bug_resolved_status_threshold');

    $t_query = new DBQuery();
    $t_sql = 'SELECT handler_id, count(*) as count FROM {bug} WHERE ' . $t_specific_where
        . ' AND handler_id <> :nouser AND status >= :status_resolved AND resolution = :resolution_fixed';
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
        'resolution_fixed' => FIXED,
    ));
    $t_query->set_limit(20);

    $t_handler_array = array();
    $t_handler_ids = array();
    while ($t_row = $t_query->fetch()) {
        $t_handler_array[$t_row['handler_id']] = (int)$t_row['count'];
        $t_handler_ids[] = $t_row['handler_id'];
    }

    if (count($t_handler_array) == 0) {
        return array();
    }

    user_cache_array_rows($t_handler_ids);

    foreach ($t_handler_array as $t_handler_id => $t_count) {
        $t_metrics[user_get_name($t_handler_id)] = $t_count;
    }

    arsort($t_metrics);

    return $t_metrics;
}

function developer_open_summary(array $p_filter = null)
{
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
        return array();
    }

    user_cache_array_rows($t_handler_ids);

    foreach ($t_handler_array as $t_handler_id => $t_count) {
        $t_metrics[user_get_name($t_handler_id)] = $t_count;
    }

    arsort($t_metrics);

    return $t_metrics;
}

function bug_status_summary(array $p_filter = null)
{
    if (null === $p_filter || !filter_is_temporary($p_filter)) {
        $t_status_enum = config_get('status_enum_string');
        $t_statuses = MantisEnum::getValues($t_status_enum);
        $t_closed_threshold = config_get('bug_closed_status_threshold');

        $t_closed_statuses = array();
        foreach ($t_statuses as $t_status_code) {
            if ($t_status_code >= $t_closed_threshold) {
                $t_closed_statuses[] = $t_status_code;
            }
        }
    } else {
        # when explicitly using a filter, do not exclude any status, to match the expected filter results
        $t_closed_statuses = array();
    }

    return bug_enum_summary(lang_get('status_enum_string'), 'status', $t_closed_statuses, $p_filter);
}


function bug_enum_summary($p_enum_string, $p_enum, array $p_exclude_codes = array(), array $p_filter = null): array
{
    $t_project_id = helper_get_current_project();
    $t_user_id = auth_get_current_user_id();
    $t_specific_where = helper_project_specific_where($t_project_id, $t_user_id);

    $t_metrics = array();
    $t_assoc_array = MantisEnum::getAssocArrayIndexedByValues($p_enum_string);

    if (!db_field_exists($p_enum, db_get_table('bug'))) {
        trigger_error(ERROR_DB_FIELD_NOT_FOUND, ERROR);
    }

    $t_query = new DBQuery();
    $t_sql = 'SELECT ' . $p_enum . ' AS enum, COUNT(*) AS bugcount FROM {bug}'
        . ' WHERE ' . $p_enum . ' IN :enum_values'
        . ' AND ' . $t_specific_where
        . ($p_filter ? ' AND {bug}.id IN :filter' : '')
        . ' GROUP BY ' . $p_enum . ' ORDER BY ' . $p_enum;
    $t_query->bind('enum_values', array_keys($t_assoc_array));
    if ($p_filter) {
        $t_query->bind('filter', filter_cache_subquery($p_filter));
    }
    $t_query->sql($t_sql);

    while ($t_row = $t_query->fetch()) {
        $t_enum_key = (int)$t_row['enum'];
        $t_bugcount = (int)$t_row['bugcount'];
        $t_label = $t_assoc_array[$t_enum_key];
        $t_metrics[$t_label] = $t_bugcount;
    }

    return $t_metrics;
}