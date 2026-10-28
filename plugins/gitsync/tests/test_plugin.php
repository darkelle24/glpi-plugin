<?php
// Real Symfony/Twig; GLPI session, sudo-mode and HTTP transport are simulated.
namespace {
    require $argv[1] ?? __DIR__ . '/vendor/autoload.php';
    const UPDATE = 2;
    $_SESSION = ['glpiactiveprofile' => ['id' => 4]];
    class Session {
        public static bool $central = true;
        public static bool $allowed = true;
        public static function checkCentralAccess(): void {
            if (!self::$central) { throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException(); }
        }
        public static function haveRight($key, $right): bool { return self::$allowed && $key === 'config' && $right === UPDATE; }
        public static function getLoginUserID(): int { return 42; }
    }
    class Config {
        public static bool $reauth = true;
        public static int $checks = 0;
        public function checkGlobal($right): void {
            self::$checks++;
            if (!self::$reauth) { throw new \RuntimeException('REAUTH_REQUIRED'); }
        }
    }
    class Plugin {
        public static bool $active = true;
        public static function isPluginActive($key): bool { return self::$active && $key === 'pluginsync'; }
    }
}
namespace Symfony\Component\HttpKernel\Exception { class AccessDeniedHttpException extends \RuntimeException {} }
namespace Glpi\Controller {
    abstract class AbstractController {
        protected function render($view, $parameters): \Symfony\Component\HttpFoundation\Response {
            return new \Symfony\Component\HttpFoundation\Response(json_encode($parameters));
        }
    }
}
namespace GlpiPlugin\Pluginsync {
    $fakeToken = str_repeat('b', 64);
    $fakeCode = 200;
    $fakeBody = '{"status":"queued"}';
    $fakeFailure = false;
    $calls = [];
    $options = [];
    function is_readable($path) { return true; }
    function file_get_contents($path) { global $fakeToken; return $fakeToken; }
    function curl_init($url) { global $calls; $calls[] = $url; return 1; }
    function curl_setopt_array($handle, $values) { global $options; $options = $values; return true; }
    function curl_setopt($handle, $key, $value) { global $options; $options[$key] = $value; return true; }
    function curl_exec($handle) {
        global $options, $fakeBody, $fakeFailure;
        if ($fakeFailure) { return false; }
        return $options[CURLOPT_WRITEFUNCTION]($handle, $fakeBody) === strlen($fakeBody);
    }
    function curl_getinfo($handle, $key) { global $fakeCode; return $fakeCode; }
    function curl_close($handle) {}
}
namespace {
    use GlpiPlugin\Pluginsync\Access;
    use GlpiPlugin\Pluginsync\Client;
    use GlpiPlugin\Pluginsync\Controller\AdminController;
    use GlpiPlugin\Pluginsync\Controller\JobController;
    use GlpiPlugin\Pluginsync\Controller\StatusController;
    use GlpiPlugin\Pluginsync\Controller\AssetsController;
    use Symfony\Component\HttpFoundation\Request;
    use Symfony\Component\Routing\Route;
    use Symfony\Component\Routing\RouteCollection;
    use Symfony\Component\Routing\RequestContext;
    use Symfony\Component\Routing\Generator\UrlGenerator;
    use Symfony\Component\Routing\Matcher\UrlMatcher;

    foreach (['Access', 'Client', 'Controller/AdminController', 'Controller/JobController',
              'Controller/StatusController', 'Controller/AssetsController'] as $file) {
        require __DIR__ . '/../pluginsync/src/' . $file . '.php';
    }
    $checks = 0;
    function check($ok, $label) {
        global $checks;
        $checks++;
        if (!$ok) { throw new \RuntimeException('FAIL: ' . $label); }
    }
    function rejects(callable $action, $text = '') {
        try { $action(); } catch (\Throwable $e) {
            check($text === '' || str_contains($e->getMessage(), $text), 'expected error: ' . $e->getMessage());
            return;
        }
        throw new \RuntimeException('Expected rejection');
    }
    putenv('PLUGINSYNC_ALLOWED_PROFILES=');
    Access::check();
    check(Config::$checks === 1, 'native sudo mode checked');
    Session::$central = false; rejects(fn() => Access::check()); Session::$central = true;
    Session::$allowed = false; rejects(fn() => Access::check()); Session::$allowed = true;
    Plugin::$active = false; rejects(fn() => Access::check()); Plugin::$active = true;
    Config::$reauth = false; rejects(fn() => Access::check(), 'REAUTH_REQUIRED'); Config::$reauth = true;
    foreach (['5', '4, 5', '0', '4,invalid'] as $profiles) {
        putenv('PLUGINSYNC_ALLOWED_PROFILES=' . $profiles); rejects(fn() => Access::check());
    }
    putenv('PLUGINSYNC_ALLOWED_PROFILES=4,5'); Access::check();
    putenv('PLUGINSYNC_ALLOWED_PROFILES=');
    rejects(fn() => Access::consumeCsrf(''));
    $token = Access::csrfToken();
    check(strlen($token) === 64, 'strong session token');
    rejects(fn() => Access::consumeCsrf('wrong'));
    $_SESSION['pluginsync_csrf']['expires'] = time() - 1;
    rejects(fn() => Access::consumeCsrf($token));
    $token = Access::csrfToken(); Access::consumeCsrf($token);
    rejects(fn() => Access::consumeCsrf($token));

    $client = new Client();
    $client->jobs(2);
    check(end($calls) === 'http://pluginsync-worker:8080/v1/jobs?page=2', 'fixed service and pagination');
    check($options[CURLOPT_FOLLOWLOCATION] === false && $options[CURLOPT_PROXY] === '', 'no redirects/proxy');
    $client->run(2);
    check(end($calls) === 'http://pluginsync-worker:8080/v1/jobs/2/run', 'fixed job run endpoint');
    check($options[CURLOPT_POSTFIELDS] === '{}', 'no client command');
    check(in_array('X-GLPI-User: 42', $options[CURLOPT_HTTPHEADER], true), 'attribution');
    rejects(fn() => $client->run(-1));
    $client->save(['name' => 'Test']);
    check(json_decode($options[CURLOPT_POSTFIELDS], true)['name'] === 'Test', 'JSON transport');
    $client->delete(2); check($options[CURLOPT_CUSTOMREQUEST] === 'DELETE', 'explicit delete');
    $fakeToken = 'password'; rejects(fn() => $client->jobs()); $fakeToken = str_repeat('b', 64);
    foreach ([401, 403, 409, 422, 503, 500, 302] as $code) {
        $fakeCode = $code; $fakeBody = '{"error":"Erreur contrôlée"}';
        rejects(fn() => $client->jobs(), 'Erreur contrôlée');
    }
    $fakeCode = 200; $fakeBody = 'invalid'; rejects(fn() => $client->jobs(), 'invalide');
    $fakeBody = str_repeat('x', 1048577); rejects(fn() => $client->jobs());
    $fakeBody = '{"status":"queued"}'; $fakeFailure = true; $before = count($calls);
    rejects(fn() => $client->run(1), 'Actualisez');
    check(count($calls) === $before + 1, 'no automatic mutation retry'); $fakeFailure = false;

    $routes = new RouteCollection();
    foreach ([AdminController::class, JobController::class, StatusController::class, AssetsController::class] as $class) {
        $attribute = (new \ReflectionMethod($class, '__invoke'))->getAttributes(\Symfony\Component\Routing\Attribute\Route::class)[0]->newInstance();
        $route = new Route('/plugins/pluginsync' . $attribute->path);
        $route->setMethods($attribute->methods);
        $route->setRequirements($attribute->requirements);
        $routes->add('@pluginsync:' . $attribute->name, $route);
    }
    $context = new RequestContext('/glpi');
    $router = new UrlGenerator($routes, $context);
    check($router->generate('@pluginsync:admin') === '/glpi/plugins/pluginsync/Admin', 'GLPI subdirectory');
    $matcher = new UrlMatcher($routes, $context);
    rejects(fn() => $matcher->match('/plugins/pluginsync/Job'));
    $context->setMethod('POST');
    check($matcher->match('/plugins/pluginsync/Job')['_route'] === '@pluginsync:job', 'POST route');
    $controller = new JobController($router);
    Session::$allowed = false; $before = count($calls);
    rejects(fn() => $controller(Request::create('/Job', 'POST')));
    check(count($calls) === $before, 'no access before permission check'); Session::$allowed = true;
    rejects(fn() => $controller(Request::create('/Job', 'POST', ['sync_csrf' => 'wrong'])));
    check(count($calls) === $before, 'CSRF rejection before worker call');
    function post($controller, array $fields) {
        return $controller(Request::create('/Job', 'POST', $fields + ['sync_csrf' => Access::csrfToken()]));
    }
    $response = post($controller, ['action' => 'run', 'id' => 1]);
    check($response->getStatusCode() === 303, 'post redirect get');
    check($_SESSION['pluginsync_notice']['ok'], 'run notice');
    post($controller, ['action' => 'save', 'name' => 'Plugin', 'url' => 'https://github.com/example/repo.git',
                       'path' => '/mnt/Apps/GLPI/repos/plugin', 'script' => 'deploy.sh', 'auto_pull' => '1']);
    $sent = json_decode($options[CURLOPT_POSTFIELDS], true);
    check($sent['auto_pull'] === true && $sent['interval_minutes'] === 15, 'automatic save defaults');
    check($sent['script'] === 'deploy.sh', 'optional script transmitted');
    post($controller, ['action' => 'delete', 'id' => 1]);
    check(!$_SESSION['pluginsync_notice']['ok'], 'explicit deletion confirmation');
    $fakeCode = 422; $fakeBody = '{"error":"Chemin refusé"}';
    post($controller, ['action' => 'save', 'name' => 'À conserver', 'path' => '../bad']);
    check($_SESSION['pluginsync_draft']['name'] === 'À conserver', 'invalid form preserved');
    $fakeCode = 200; $fakeBody = '{"jobs":[],"page":1,"total":0,"pages":1,"counts":{},"host_root":"/mnt/Apps/GLPI/repos"}';
    $response = (new AdminController())(Request::create('/Admin'));
    check($response->headers->get('Cache-Control') === 'no-store, private', 'private admin page');
    check(!isset($_SESSION['pluginsync_draft']), 'draft consumed once');
    $response = (new StatusController())(Request::create('/Statuses'));
    check($response->getStatusCode() === 200, 'status feed');
    check((new AssetsController())('../secrets/token')->getStatusCode() === 404, 'asset traversal denied');
    check((new AssetsController())('app.css')->getStatusCode() === 200, 'stylesheet accessible');

    // Real template compilation/rendering, GLPI's outer layout replaced only for tests.
    $filesystem = new \Twig\Loader\FilesystemLoader();
    $filesystem->addPath(__DIR__ . '/../pluginsync/templates', 'pluginsync');
    $parent = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Git Sync — Aperçu</title>'
            . '<style>body{margin:0;padding:32px;background:#f6f7fb;font-family:Inter,Segoe UI,Arial,sans-serif}button,input{font-family:inherit}</style></head><body>{% block content_body %}{% endblock %}</body></html>';
    $loader = new \Twig\Loader\ChainLoader([
        new \Twig\Loader\ArrayLoader(['layout/page_without_tabs.html.twig' => $parent]), $filesystem,
    ]);
    $twig = new \Twig\Environment($loader, ['autoescape' => 'html', 'strict_variables' => true]);
    $twig->addFunction(new \Twig\TwigFunction('path', fn($name, $params = []) => $router->generate($name, $params)));
    $job = ['id' => 1, 'name' => 'Plugins GLPI', 'url' => 'https://github.com/darkelle24/glpi-plugin.git',
            'branch' => 'main', 'path' => 'glpi-plugin', 'host_path' => '/mnt/Apps/GLPI/repos/glpi-plugin',
            'script' => 'deploy.sh', 'auto_pull' => true, 'interval_minutes' => 15,
            'status' => 'success', 'message' => 'Dépôt synchronisé et script terminé avec succès.',
            'last_attempt' => '2026-10-28T10:42:00Z', 'next_run_at' => '2026-10-28T10:57:00Z'];
    $data = ['jobs' => [$job], 'total' => 1, 'page' => 1, 'pages' => 1,
             'counts' => ['success' => 1], 'host_root' => '/mnt/Apps/GLPI/repos'];
    $values = ['data' => $data, 'error' => null, 'edit' => null, 'log' => null, 'notice' => null, 'sync_csrf' => 'TOKEN'];
    $html = $twig->render('@pluginsync/admin.html.twig', $values);
    check(str_contains($html, 'Git Sync'), 'requested product name');
    check(str_contains($html, 'Pull automatique'), 'automatic checkbox');
    check(str_contains($html, 'Synchroniser'), 'per-repository button');
    $values['log'] = ['logs' => '<script>alert("XSS")</script>'];
    $values['data']['jobs'][0]['name'] = '<img src=x onerror=alert(1)>';
    $html = $twig->render('@pluginsync/admin.html.twig', $values);
    check(!str_contains($html, '<script>alert') && str_contains($html, '&lt;script&gt;'), 'logs escaped');
    check(!str_contains($html, '<img src=x') && str_contains($html, '&lt;img'), 'repository name escaped');
    foreach (['running', 'error', 'idle', 'queued'] as $state) {
        $values['data'] = $data; $values['data']['jobs'][0]['status'] = $state;
        check($twig->render('@pluginsync/admin.html.twig', $values) !== '', 'render state ' . $state);
    }
    $values['data'] = $data; $values['edit'] = $job;
    check(str_contains($twig->render('@pluginsync/admin.html.twig', $values), 'Retirer ce dépôt'), 'edit and delete form');
    $values['data']['jobs'] = []; $values['data']['total'] = 0; $values['edit'] = null;
    check(str_contains($twig->render('@pluginsync/admin.html.twig', $values), 'Votre premier dépôt'), 'empty state');
    $values['data'] = null; $values['error'] = 'Service inaccessible';
    check(str_contains($twig->render('@pluginsync/admin.html.twig', $values), 'Service inaccessible'), 'offline state');

    if (isset($argv[2])) {
        $jobs = [$job];
        $running = $job; $running['id'] = 2; $running['name'] = 'Thème intranet';
        $running['url'] = 'https://github.com/exemple/theme-intranet.git';
        $running['host_path'] = '/mnt/Apps/GLPI/repos/theme-intranet'; $running['status'] = 'running';
        $running['script'] = ''; $running['message'] = 'Récupération des dernières modifications…';
        $running['auto_pull'] = false; $running['next_run_at'] = null;
        $jobs[] = $running;
        $failed = $job; $failed['id'] = 3; $failed['name'] = 'Connecteur inventaire';
        $failed['url'] = 'https://github.com/exemple/inventory.git';
        $failed['host_path'] = '/mnt/Apps/GLPI/repos/inventory'; $failed['status'] = 'error';
        $failed['message'] = 'Le script a retourné une erreur. Consultez le journal.';
        $failed['auto_pull'] = false; $failed['next_run_at'] = null;
        $jobs[] = $failed;
        $idle = $job; $idle['id'] = 4; $idle['name'] = 'Documentation interne';
        $idle['url'] = 'https://github.com/exemple/documentation.git';
        $idle['host_path'] = '/mnt/Apps/GLPI/repos/documentation'; $idle['status'] = 'idle';
        $idle['script'] = ''; $idle['message'] = ''; $idle['last_attempt'] = null;
        $jobs[] = $idle;
        $values = ['data' => ['jobs' => $jobs, 'total' => 4, 'page' => 1, 'pages' => 1,
                    'counts' => ['success' => 1, 'running' => 1, 'error' => 1], 'host_root' => '/mnt/Apps/GLPI/repos'],
                   'error' => null, 'edit' => null, 'log' => null, 'notice' => null, 'sync_csrf' => 'DEMO'];
        $preview = $twig->render('@pluginsync/admin.html.twig', $values);
        $preview = preg_replace('~<script src=.*?</script>~', '', $preview);
        $preview = preg_replace('~<link rel="stylesheet" href="[^"]+">~',
            '<style>' . file_get_contents(__DIR__ . '/../pluginsync/public/app.css') . '</style>', $preview);
        $preview = str_replace('<body>', '<body><p style="text-align:center;color:#8a80a6;font-size:12px">APERÇU VISUEL — données fictives, actions désactivées</p>', $preview);
        $preview = str_replace('<form method="post"', '<form onsubmit="return false" method="post"', $preview);
        $preview = str_replace('href="/glpi/', 'href="#preview-', $preview);
        file_put_contents($argv[2], $preview);
    }
    echo "OK: $checks PHP/Symfony/Twig assertions.\n";
}
