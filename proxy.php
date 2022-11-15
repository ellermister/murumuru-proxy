<?php

declare(strict_types=1);

use Swoole\Constant;
use Swoole\Coroutine\FastCGI\Client;
use Swoole\Coroutine\Http\Client as HttpClient;
use Swoole\Coroutine\FastCGI\Proxy;
use Swoole\FastCGI\HttpRequest;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;
use Swoole\Process;
use Swoole\Coroutine;
use Swoole\Coroutine\Server\Connection;
use Swoole\Http;

define("MINIFY_DIST", getenv('MINIFY_DIST') ?: '/dist/');
define("WEB_ROOT", getenv('WEB_ROOT') ?: '/app'); //尾部不要 /
define("CGI_WEB_ROOT", getenv('CGI_WEB_ROOT') ?: '/app'); //尾部不要 /

define("CGI_URL", getenv('CGI_URL') ?: '172.22.0.10:9000');

define("HTTPS_ENABLED", (getenv('HTTPS_ENABLED') ?: 'false') == 'true');
define("DEBUG_OUTPUT", (getenv('DEBUG_OUTPUT') ?: 'false') == 'true');
define("BASE64_INLINE_MAX_SIZE", getenv('BASE64_INLINE_MAX_SIZE') ?: 1024 * 30);
define("LISTEN_PORT", intval(getenv('LISTEN_PORT')) ?: 80);

define("MINIMIZE_STATIC_RESOURCE", (getenv('MINIMIZE_STATIC_RESOURCE') ?: 'true') == 'true');

define("DISABLE_CACHE", (getenv('DISABLE_CACHE') ?: 'false') == 'true');

const WEBP_FROM_EXTENSIONS = ["jpg", "png", "jpeg", "bmp"];


Co::set(['hook_flags' => SWOOLE_HOOK_ALL]);

function str_debug($chanel, $text, $level = 'info')
{
    if (DEBUG_OUTPUT) {
        if ($level == 'info') {
            echo sprintf("\033[42;37m [%s] \033[0m [%s] %s\n", $chanel, date('Y-m-d H:i:s'), $text);
        } else if ($level == 'warning') {
            echo sprintf("\033[43;37m [%s] \033[0m [%s] %s\n", $chanel, date('Y-m-d H:i:s'), $text);
        } else {
            // 没有了
        }
    }
}


class StaticResource
{

    public function extremeInlineResource($selfPath, $raw, $host)
    {
        //存在非标准的 " '于 url()，进行替换
        $raw = preg_replace_callback('~url\(([^)]+)\)~', function ($matches) {
            $replaced = trim($matches[1], '"\'');
            if ($replaced != $matches[1]) {
                str_debug('format-inline', sprintf("%s to %s", $matches[1], $replaced));
            }
            return str_replace($matches[1], $replaced, $matches[0]);
        }, $raw);

        // 移除所有本站域名的绝对路径到相对路径
        $raw = preg_replace(sprintf('#(?:https?:)?//%s/#is', addslashes($host)), '/', $raw);

        // fix with full path
        // char '@' from less
        $raw = preg_replace_callback('~url\((?!(?:\/|\/\/|http|data|@))([^\)]+)\)~', function ($matches) use ($selfPath) {
            $fileQuery = $matches[1];
            $file = trim($fileQuery, "'\"");
            $file = preg_replace('#(\?|\#).*#', '', $file);
            $path = dirname($selfPath) . '/' . $file;
            $urlPath = str_replace(WEB_ROOT, '', realpath($path));

            str_debug('fix-inline', sprintf("source:%s to %s", $matches[1], $urlPath));
            return str_replace($matches[1], $urlPath, $matches[0]);
        }, $raw);

        // replace with inline resource
        $raw = preg_replace_callback('~url\((?=/[a-z])([^\)]+)\)~', function ($matches) use ($selfPath) {
            $fileQuery = $matches[1];
            $file = trim($fileQuery, "'\"");
            $file = preg_replace('#(\?|\#).*#', '', $file);
            $path = WEB_ROOT . '/' . $file;

            $result = $matches[0];
            if (is_file($path) && filesize($path) < BASE64_INLINE_MAX_SIZE) {
                $binBase64 = base64_encode(file_get_contents($path));
                $mine = mime_content_type($path);
                $inlineBase64Resource = sprintf("data:%s;base64,%s", $mine, $binBase64);
                str_debug('base64-inline', sprintf("source:%s to base64-inline size: %s", $matches[1], filesize($path)));

                $result = str_replace($matches[1], $inlineBase64Resource, $matches[0]);
            }
            return $result;
        }, $raw);

        // replace with inline resource for less
        // @icon-reviews: "/icon-reviews.svg"

        $raw = preg_replace_callback('~@[A-Za-z][A-Za-z0-9-_]*:\s+"(?=/[A-Za-z].*(?:svg|png|jpg|jpeg|gif))([^"]+)"~', function ($matches) use ($selfPath) {
            $fileQuery = $matches[1];
            $file = trim($fileQuery, "'\"");
            $file = preg_replace('#(\?|\#).*#', '', $file);
            $path = WEB_ROOT . '/' . $file;

            $result = $matches[0];
            if (is_file($path) && filesize($path) < BASE64_INLINE_MAX_SIZE) {
                $binBase64 = base64_encode(file_get_contents($path));
                $mine = mime_content_type($path);
                $inlineBase64Resource = sprintf("data:%s;base64,%s", $mine, $binBase64);
                str_debug('base64-inline', sprintf("source:%s to base64-inline size: %s", $matches[1], filesize($path)));

                $result = str_replace($matches[1], $inlineBase64Resource, $matches[0]);
            }
            return $result;
        }, $raw);
        return $raw;
    }


    public function minimizeCss($html, $webRoot, $host, $path_info)
    {
        $webRoot = rtrim($webRoot, '/');

        if (preg_match_all('#<link.*?href=(?:"|\')(?=.*\.css.*)([^"|\']+)(?:"|\').*?>#i', $html, $matches)) {
            $all = [];
            $pathDir = dirname($path_info);
            !is_dir($pathDir. $pathDir) && $pathDir = '/';

            // http 或 / 路径形式的本站资源
            $replaceSources = [];
            $cacheFileNames = [];
            foreach ($matches[1] as  $index => $match) {
                // 过滤掉非本站资源的链接

                if (preg_match(sprintf('#https?://%s(/.*\.css)#is', addslashes($host)), $match, $domainMatches)) {
                    $all[] = $domainMatches[1];
                    $replaceSources[] = $matches[0][$index];
                    $cacheFileNames []= $match;
                }else if(preg_match('#^(?!http|//)(.*\.css)#is', $match, $domainMatches)) {
                    $all[] = $domainMatches[1];
                    $replaceSources[] = $matches[0][$index];
                    $cacheFileNames []= $match;
                }

            }
            $cacheKey = md5(implode('', $cacheFileNames));
            $all_css = sprintf('%sall_css_%s.css', MINIFY_DIST, $cacheKey);

            if (!is_file($webRoot . $all_css) || DISABLE_CACHE) {
                $bigCssContent = '';
                foreach ($all as $cssFile) {
                    $cssPath = $webRoot .$pathDir .$cssFile;
                    if (is_file($cssPath)) {
                        $raw = file_get_contents($cssPath);

                        $raw = $this->extremeInlineResource($cssPath, $raw, $host);

                        $bigCssContent .= $raw;
                    } else {
                        // warning output, css file not found!
                        str_debug('minimized', sprintf('css file not found: %s', $cssPath), 'warning');
                    }
                }

                /* remove comments */
                $bigCssContent = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $bigCssContent);
                /* remove tabs, spaces, newlines, etc. */
                $bigCssContent = str_replace(array("
", "\r", "\n", "\t", '  ', '    ', '    '), '', $bigCssContent);


                file_put_contents($webRoot . $all_css, $bigCssContent);

                str_debug('minimized', sprintf("file to %s", $all_css));
            }

            $html = str_replace($replaceSources, '', $html);

            $html = str_replace('</head>', '<link href="' . $all_css . '" rel="stylesheet" /> </head>', $html);
        }

        $html = $this->minimizeLess(...func_get_args());
        return $html;
    }

    public function minimizeLess(string $html, string $webRoot, string $host, $path_info){
        if (preg_match_all('#<link.*?href=(?:"|\')(?=.*\.less.*)([^"|\']+)(?:"|\').*?>#i', $html, $matches)) {
            $all = [];
            $pathDir = dirname($path_info);
            !is_dir($pathDir. $pathDir) && $pathDir = '/';

            // http 或 / 路径形式的本站资源
            $replaceSources = [];
            $cacheFileNames = [];
            foreach ($matches[1] as  $index => $match) {
                // 过滤掉非本站资源的链接

                if (preg_match(sprintf('#https?://%s(/.*\.less)#is', addslashes($host)), $match, $domainMatches)) {
                    $all[] = $domainMatches[1];
                    $replaceSources[] = $matches[0][$index];
                    $cacheFileNames []= $match;
                }else if(preg_match('#^(?!http|//)(.*\.less)#is', $match, $domainMatches)) {
                    $all[] = $domainMatches[1];
                    $replaceSources[] = $matches[0][$index];
                    $cacheFileNames []= $match;
                }

            }
            $cacheKey = md5(implode('', $cacheFileNames));
            $all_less = sprintf('%sall_less_%s.less', MINIFY_DIST, $cacheKey);

            if (!is_file($webRoot . $all_less) || DISABLE_CACHE) {
                $bigLessContent = '';
                foreach ($all as $cssFile) {
                    $cssPath = $webRoot .$pathDir .$cssFile;
                    if (is_file($cssPath)) {
                        $raw = file_get_contents($cssPath);

                        $raw = $this->extremeInlineResource($cssPath, $raw, $host);

                        $bigLessContent .= $raw;
                    } else {
                        // warning output, css file not found!
                        str_debug('minimized', sprintf('css file not found: %s', $cssPath), 'warning');
                    }
                }

                file_put_contents($webRoot . $all_less, $bigLessContent);

                str_debug('minimized', sprintf("file to %s", $all_less));
            }

            $html = str_replace($replaceSources, '', $html);

            $html = str_replace('<head>', '<head><link rel="stylesheet/less" type="text/css"  href="' . $all_less . '" />', $html);
        }

        return $html;
    }

    public function minimizeJs(string $html, string $webRoot, string $host, $path_info)
    {
        $webRoot = rtrim($webRoot, '/');
        if (preg_match_all('#<script.*?src=(?:"|\')(?=.*\.js.*)([^"|\']+)(?:"|\').*?>.*?</script>#i', $html, $matches)) {
            $all = [];

            $pathDir = dirname($path_info);
            !is_dir($pathDir. $pathDir) && $pathDir = '/';


            $replaceSources = [];
            $cacheFileNames = [];
            foreach ($matches[1] as $index => $match) {

                if (preg_match(sprintf('#https?://%s(/.*\.js)#is', addslashes($host)), $match, $domainMatches)) {
                    $all[] = $domainMatches[2];
                    $cacheFileNames []= $match;
                    $replaceSources[] = $matches[0][$index];
                }else if(preg_match('#^(?!http|//)(.*\.js)#is', $match, $domainMatches)) {
                    $all[] = $domainMatches[1];
                    $replaceSources[] = $matches[0][$index];
                    $cacheFileNames []= $match;
                }
            }

            $cacheKey = md5(implode('', $cacheFileNames));
            $all_js = sprintf('%sall_js_%s.js', MINIFY_DIST, $cacheKey);

            if (!is_file($webRoot . $all_js) || DISABLE_CACHE) {
                $bigJsContent = '';
                foreach ($all as $jsFile) {

                    $jsPath = $webRoot .$pathDir .$jsFile;

                    if (is_file($jsPath)) {
                        $raw = file_get_contents($jsPath);
                        $bigJsContent .= empty($bigJsContent) ? $raw : "\n" . $raw;
                    } else {
                        // warning output, js file not found!
                    }
                }

                file_put_contents($webRoot . $all_js, $bigJsContent);

                str_debug('minimized', sprintf("file to %s", $all_js));
            }

            $html = str_replace($replaceSources, '', $html);

            $html = str_replace('</head>', '<script defer src="' . $all_js . '"></script> </head>', $html);
        }
        return $html;
    }

    public function minimizeHTML(string $html, string $webRoot, string $host, $path_info)
    {
        // 替换站内链接全路径为根路径
        return preg_replace_callback('#<body[^>]*>(.*)</body>#is', function($matches) use($host){
            $body = $matches[1];
            $body = preg_replace_callback(sprintf('#<\w+[^>]+(?:href|src)=(?:"|\')(https?://%s)([^("|\')]+)(?:"|\')[^>]*>#is', addslashes($host)), function ($matches) {
                return str_replace($matches[1], '', $matches[0]);
            }, $body);
            return str_replace($matches[1], $body, $matches[0]);
        }, $html);
    }
}

/**
 * 处理CGI通信
 */
class FastCGIProxy extends Swoole\Coroutine\FastCGI\Proxy
{

    /**
     * @var StaticResource
     */
    protected $staticHandler;

    public function setStaticHandler(StaticResource $handler)
    {
        $this->staticHandler = $handler;
    }

    public function staticFileFiltrate(HttpRequest $request, $userResponse): bool
    {
        if ($userResponse instanceof \Swoole\Http\Response) {
            $extension = pathinfo($request->getScriptFilename(), PATHINFO_EXTENSION);
            if ($extension !== 'php') {
                $scriptFileName = $request->getScriptFilename();
                $appScriptFileName = str_replace(CGI_WEB_ROOT, WEB_ROOT, $scriptFileName ?? '');
                $realPath = realpath($appScriptFileName);
                if (!$realPath || strpos($realPath, WEB_ROOT) !== 0 || !is_file($realPath)) {
                    $userResponse->status(Http\Status::NOT_FOUND);
                } else {
                    if (in_array($extension, WEBP_FROM_EXTENSIONS)) {
                        $proxyUri = str_replace('^' . WEB_ROOT, '', '^' . $realPath);
                        $cli = new HttpClient('127.0.0.1', 3333);

                        $cli->setHeaders($request->getHeaders());
                        $cli->get($proxyUri);
                        $cli->close();

                        // 200 正常响应, 304 缓存未改变
                        if ($cli->statusCode != -1) {

                            $userResponse->header = $cli->getHeaders();

                            // Content-Length and Transfer-Encoding are mutually exclusive HTTP headers
                            // https://bugs.launchpad.net/glance/+bug/981332
                            unset($userResponse->header['content-length']);

                            $userResponse->end($cli->body);

                            str_debug('webp', sprintf("request %s, Compress size from %.2fK to %.2fK",
                                $proxyUri,
                                filesize($realPath) / 1024,
                                strlen($cli->body) / 1024
                            ));
                        } else {
                            str_debug('webp', sprintf("request %s, Downgrade Response and WebP server may be down, (code: %s): %s",
                                $proxyUri,
                                $cli->errCode,
                                $cli->errMsg,
                            ), 'warning');

                            $userResponse->sendfile($realPath);
                        }

                    } else {
                        $userResponse->sendfile($realPath);
                    }
                }
                return true;
            }
            return false;
        }
        throw new InvalidArgumentException('Not supported on ' . get_class($userResponse));
    }

    public function pass($userRequest, $userResponse): void
    {
        $context = Co::getContext();
        $context['_minimize'] = isset($userRequest->get['_minimize']) ? intval(isset($userRequest->get['_minimize'])): null;
        $skipMinimize = $context['_minimize'] == 2;

        $host = $userRequest->header['host'];
        $path_info = $userRequest->server['path_info'];
        if (!($userRequest instanceof HttpRequest)) {
            $request = $this->translateRequest($userRequest);
        } else {
            $request = $userRequest;
        }
        unset($userRequest);

        if ($this->staticFileFilter) {
            $filter = $this->staticFileFilter;
            if ($filter($request, $userResponse)) {
                return;
            }
        }

        // 伪静态重写
        $scriptFile = $request->getScriptFilename();
        $appWebPath = str_replace(CGI_WEB_ROOT, WEB_ROOT, $scriptFile ?? '');
        if (!is_file($appWebPath)) {
            $request->withScriptFilename($this->documentRoot . '/index.php');
        }

        $client = new Client($this->host, $this->port);
        $response = $client->execute($request, $this->timeout);

        $htmlMimeType = 'text/html;';
        if (!$skipMinimize && MINIMIZE_STATIC_RESOURCE && $this->staticHandler && substr($response->getHeader('Content-type'), 0, strlen($htmlMimeType)) == $htmlMimeType) {
            $body = $response->getBody();
            $body = $this->staticHandler->minimizeCss($body, WEB_ROOT, $host, $path_info);
            $body = $this->staticHandler->minimizeJs($body, WEB_ROOT, $host, $path_info);
            $body = $this->staticHandler->minimizeHTML($body, WEB_ROOT, $host, $path_info);
            $response->withBody($body);
        }

        if ($CGIErrorString = $response->getError()) {
            file_put_contents("php://stderr", $CGIErrorString);
        }

        $this->translateResponse($response, $userResponse);
    }
}

/**
 * Swoole proxy rewrite server
 */
class SPWrite
{
    protected $server;
    protected $proxy;

    public function __construct()
    {
        $this->server = new Server('0.0.0.0', LISTEN_PORT, SWOOLE_BASE);
        $this->server->set(
            [
                Constant::OPTION_WORKER_NUM               => swoole_cpu_num() * 2,
                Constant::OPTION_HTTP_PARSE_COOKIE        => false,
                Constant::OPTION_HTTP_PARSE_POST          => false,
                Constant::OPTION_DOCUMENT_ROOT            => WEB_ROOT,
                Constant::OPTION_ENABLE_STATIC_HANDLER    => true,
                Constant::OPTION_STATIC_HANDLER_LOCATIONS => [
                    MINIFY_DIST,
                ],
            ]
        );

        $this->proxy = new FastCGIProxy(CGI_URL, CGI_WEB_ROOT);
        $this->proxy->withHttps(HTTPS_ENABLED);
        $this->proxy->setStaticHandler(new StaticResource());

        $this->server->on(
            'request',
            function (Request $request, Response $response) {
                if($request->server['path_info'] == '/muru-cgi/clear'){
                    $count = $this->clearCache();
                    $response->end('Finished clearing cache:'. $count);
                }else{
                    $this->proxy->pass($request, $response);
                }
            }
        );
    }


    public function clearCache(): int
    {
        if(empty(MINIFY_DIST)){
            return 0;
        }

        $count = 0;
        $files = glob(WEB_ROOT.MINIFY_DIST.'*');
        foreach ($files as $file){
            if($file){
                $count += unlink($file)? 1: 0;
            }
        }
        return $count;
    }


    public function start()
    {
        $this->server->start();
    }
}

$wpw = new SPWrite();
$wpw->start();
