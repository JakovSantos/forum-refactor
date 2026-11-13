<?php

declare(strict_types=1);

use App\Http\HttpException;
use Bramus\Router\Router;

/**
 * Route table. Every URL the app answers is listed here.
 *
 * ID segments use ([^/]+) on purpose. The controller checks that the value is
 * a positive whole number and answers 400 if it is not, so a malformed ID
 * gets one documented response instead of an anonymous router 404.
 */
return static function (Router $router): void {
    $router->setNamespace('\App\Controllers');

    // Public reading
    $router->get('/', 'DiscussionController@index');
    $router->get('/discussions/([^/]+)', 'DiscussionController@show');

    // Accounts
    $router->get('/register', 'AuthController@showRegister');
    $router->post('/register', 'AuthController@register');
    $router->get('/login', 'AuthController@showLogin');
    $router->post('/login', 'AuthController@login');
    $router->post('/logout', 'AuthController@logout');

    // Writing (login and CSRF token are checked inside the controller)
    $router->post('/discussions/([^/]+)/comments', 'CommentController@store');
    $router->post('/discussions/([^/]+)/comments/([^/]+)/replies', 'CommentController@reply');

    // Anything else, including a GET to a POST-only URL such as /logout
    $router->set404(static function (): void {
        throw new HttpException(404, 'That page does not exist.');
    });
};
