<?php

use Slim\App;
use Slim\Http\Request;
use Slim\Http\Response;

/**
	* @var App $g_app
	*/
$g_app->group('/dashboard', function () use ($g_app) {
		$g_app->get('', 'rest_user_get');
});

/**
	* A method that does the work to handle getting a set of localized strings via REST API.
	*
	* @param Request $p_request The request.
	* @param Response $p_response The response.
	* @param array $p_args Arguments
	* @return Response The augmented response.
	*/
function rest_lang_get(Request $p_request, Response $p_response, array $p_args): Response
{
		return $p_response->withStatus(HTTP_STATUS_SUCCESS)->withJson([
				'data' => 'test_data'
		]);
}