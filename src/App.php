<?php

declare(strict_types=1);

namespace Vigil;

use Vigil\Controllers\AdminController;
use Vigil\Controllers\PublicController;
use Vigil\Http\Request;
use Vigil\Http\Response;
use Vigil\Http\Router;
use Vigil\Http\Session;
use Vigil\Monitor\Checker;
use Vigil\Monitor\Monitors;
use Vigil\Monitor\Runner;
use Vigil\Notify\Channels;
use Vigil\Notify\Notifier;

/**
 * Wires the pieces together. One instance per request (web) or per command (CLI).
 */
final class App
{
    public readonly Database $db;
    public readonly Settings $settings;
    public readonly Session $session;
    public readonly Auth $auth;
    public readonly Monitors $monitors;
    public readonly Stats $stats;
    public readonly Notifier $notifier;
    public readonly Runner $runner;

    public function __construct(public readonly Config $config)
    {
        date_default_timezone_set((string) $config->get('timezone') ?: 'UTC');

        $this->db = new Database($config->databasePath());
        $this->settings = new Settings($this->db);
        $this->session = new Session();
        $this->auth = new Auth($this->db, $this->session);
        $this->monitors = new Monitors($this->db);
        $this->stats = new Stats($this->db);
        $this->notifier = new Notifier($this->db, new Channels($this->settings));
        $this->runner = new Runner(
            db: $this->db,
            checker: new Checker((string) $config->get('user_agent')),
            notifier: $this->notifier,
            settings: $this->settings,
            lockFile: dirname($config->databasePath() === ':memory:' ? sys_get_temp_dir() . '/x' : $config->databasePath()) . '/vigil.lock',
            dashboardUrl: rtrim((string) $config->get('app_url'), '/'),
        );
    }

    public static function boot(string $root, array $overrides = []): self
    {
        return new self(Config::load($root, $overrides));
    }

    public function handle(Request $request): Response
    {
        $isAdmin = str_starts_with($request->path, '/admin');
        if ($isAdmin) {
            $this->session->start($request->secure, $this->config->baseUrl());
        }

        $router = new Router();
        $public = new PublicController($this);
        $admin = new AdminController($this);

        $router->add('GET', '/', $public->statusPage(...));
        $router->add('GET', '/api/status.json', $public->api(...));
        $router->add('GET', '/api/status', $public->api(...));
        $router->add('GET', '/badge/{slug}/{kind}', $public->badge(...));
        $router->add('GET|POST', '/hb/{token}', $public->heartbeat(...));
        $router->add('GET|POST', '/hb/{token}/fail', $public->heartbeatFail(...));
        $router->add('GET|POST', '/cron/{token}', $public->cron(...));

        $router->add('GET|POST', '/admin/setup', $admin->setup(...));
        $router->add('GET|POST', '/admin/login', $admin->login(...));
        $router->add('POST', '/admin/logout', $admin->logout(...));
        $router->add('GET', '/admin', $admin->dashboard(...));
        $router->add('GET', '/admin/monitors/new', $admin->monitorForm(...));
        $router->add('POST', '/admin/monitors', $admin->monitorSave(...));
        $router->add('GET', '/admin/monitors/{id}', $admin->monitorShow(...));
        $router->add('GET', '/admin/monitors/{id}/edit', $admin->monitorForm(...));
        $router->add('POST', '/admin/monitors/{id}', $admin->monitorSave(...));
        $router->add('POST', '/admin/monitors/{id}/{action}', $admin->monitorAction(...));
        $router->add('GET', '/admin/incidents', $admin->incidents(...));
        $router->add('GET', '/admin/channels', $admin->channels(...));
        $router->add('GET', '/admin/channels/new', $admin->channelForm(...));
        $router->add('POST', '/admin/channels', $admin->channelSave(...));
        $router->add('GET', '/admin/channels/{id}/edit', $admin->channelForm(...));
        $router->add('POST', '/admin/channels/{id}', $admin->channelSave(...));
        $router->add('POST', '/admin/channels/{id}/{action}', $admin->channelAction(...));
        $router->add('GET', '/admin/notices', $admin->notices(...));
        $router->add('POST', '/admin/notices', $admin->noticeSave(...));
        $router->add('POST', '/admin/notices/{id}/{action}', $admin->noticeAction(...));
        $router->add('GET', '/admin/settings', $admin->settings(...));
        $router->add('POST', '/admin/settings', $admin->settingsSave(...));
        $router->add('POST', '/admin/settings/password', $admin->passwordSave(...));

        if ($isAdmin && ($guard = $this->guard($request)) !== null) {
            return $guard;
        }

        try {
            return $router->dispatch($request) ?? $public->notFound();
        } catch (NotFound) {
            return $public->notFound();
        }
    }

    /** Admin pages need a signed-in user (or first-run setup) and a CSRF token on every POST. */
    private function guard(Request $request): ?Response
    {
        $open = in_array($request->path, ['/admin/setup', '/admin/login'], true);

        if (!$this->auth->hasUsers()) {
            return $request->path === '/admin/setup' ? null : Response::redirect($this->url('admin/setup'));
        }
        if ($request->path === '/admin/setup') {
            return Response::redirect($this->url('admin'));
        }
        if (!$open && $this->auth->user() === null) {
            return Response::redirect($this->url('admin/login'));
        }
        if ($request->method === 'POST' && !$this->session->validCsrf($request->post['_token'] ?? null)) {
            return Response::html('<p>This form expired. Go back, reload the page and try again.</p>', 419);
        }

        return null;
    }

    public function view(): View
    {
        return new View(VIGIL_ROOT . '/templates', $this->config, $this->settings, $this->session, $this->auth->user());
    }

    public function url(string $path = ''): string
    {
        return $this->config->baseUrl() . ltrim($path, '/');
    }
}
