<?php

namespace Mix\FastRoute;

use FastRoute\Dispatcher;
use Mix\Http\Message\Factory\StreamFactory;
use Mix\Http\Message\Response;
use Mix\Http\Message\ServerRequest;
use Mix\Http\Server\Middleware\MiddlewareDispatcher;
use Mix\FastRoute\Exception\NotFoundException;
use Mix\Http\Server\ServerHandlerInterface;
use Mix\Micro\Route\RouterInterface;

/**
 * Class Router
 * @package Mix\FastRoute
 */
class Router implements ServerHandlerInterface
{

    /**
     * @var array
     */
    protected $middleware;

    /**
     * @var array
     */
    protected $options;

    /**
     * @var Dispatcher
     */
    protected $dispatcher;

    /**
     * @var array
     */
    protected $data = [];

    /**
     * Router constructor.
     * @param array $middleware
     */
    public function __construct(array $middleware = [])
    {
        $this->middleware = $middleware;
        $this->options    = [
            'routeParser'    => 'FastRoute\\RouteParser\\Std',
            'dataGenerator'  => 'FastRoute\\DataGenerator\\GroupCountBased',
            'dispatcher'     => 'FastRoute\\Dispatcher\\GroupCountBased',
            'routeCollector' => 'Mix\\FastRoute\\RouteCollector',
        ];
    }

    /**
     * Parse
     * @param callable $routeDefinitionCallback
     */
    public function parse(callable $routeDefinitionCallback)
    {
        $options = $this->options;
        /** @var RouteCollector $routeCollector */
        $routeCollector = new $options['routeCollector'](
            new $options['routeParser'], new $options['dataGenerator']
        );
        $routeDefinitionCallback($routeCollector);
        $this->data       = $routeCollector->getData();
        $this->dispatcher = new $options['dispatcher']($this->data);
    }

    /**
     * 获取 url 规则映射的全部 service 名称
     *
     * Url                  Service
     * /                    index
     * /foo                 foo
     * /foo/bar             foo
     * /foo/bar/baz         foo
     * /foo/bar/baz/cat     foo.bar
     * /v1/foo/bar          v1.foo
     * /v1/foo/bar/baz      v1.foo
     * /v1/foo/bar/baz/cat  v1.foo.bar
     *
     * @return string[]
     */
    public function services()
    {
        foreach ($this->data as $datum) {
            foreach ($datum as $method => $item) {
                
            }
        }
    }

    /**
     * Handle HTTP
     * @param ServerRequest $request
     * @param Response $response
     * @throws \Throwable
     */
    public function handleHTTP(ServerRequest $request, Response $response)
    {
        // 支持 micro web 的代理
        // micro web 代理无法将 /foo/ 后面的杠传递过来
        $basePath   = $request->getHeaderLine('x-micro-web-base-path');
        $isMicroWeb = $basePath ? true : false;
        if ($isMicroWeb) {
            $uri = $request->getUri();
            $uri->withPath(sprintf('%s%s', $basePath, $uri->getPath() == '/' ? '' : $uri->getPath()));
            $serverParams                = $request->getServerParams();
            $serverParams['request_uri'] = sprintf('%s%s', $basePath, $serverParams['request_uri'] == '/' ? '' : $serverParams['request_uri']);
            $serverParams['path_info']   = sprintf('%s%s', $basePath, $serverParams['path_info'] == '/' ? '' : $serverParams['path_info']);
            $request->withServerParams($serverParams);
        }

        // 路由匹配
        try {
            if (!isset($this->dispatcher)) {
                throw new NotFoundException('Not Found (#404)');
            }
            $result = $this->dispatcher->dispatch($request->getMethod(), $request->getServerParams()['path_info'] ?: '/');
            switch ($result[0]) {
                case \FastRoute\Dispatcher::FOUND:
                    list($handler, $middleware) = $result[1];
                    $vars       = $result[2];
                    $middleware = array_merge($this->middleware, $middleware);
                    break;
                default:
                    throw new NotFoundException('Not Found (#404)');
            }
        } catch (NotFoundException $ex) {
            // 404 处理
            $this->error404($ex, $response)->send();
            return;
        }
        // 保存路由参数
        foreach ($vars as $key => $value) {
            $request->withAttribute($key, $value);
        }

        // 通过中间件执行
        $process    = function (ServerRequest $request, Response $response) use ($handler) {
            try {
                // 构造方法内的参数是为了方便继承封装使用
                // 为了支持 \Closure 移除了构造方法传参数，为路由支持 websocket
                $response = call_user_func($handler, $request, $response);
            } catch (\Throwable $ex) {
                // 500 处理
                $this->error500($ex, $response)->send();
                // 抛出错误，记录日志
                throw $ex;
            }
            return $response;
        };
        $dispatcher = new MiddlewareDispatcher($middleware, $process, $request, $response);
        $response   = $dispatcher->dispatch();
        /** @var Response $response */
        $response->send();
    }

    /**
     * 404 处理
     * @param \Throwable $exception
     * @param Response $response
     * @return Response
     */
    public function error404(\Throwable $exception, Response $response): Response
    {
        $content = '404 Not Found';
        $body    = (new StreamFactory())->createStream($content);
        return $response
            ->withContentType('text/plain')
            ->withBody($body)
            ->withStatus(404);
    }

    /**
     * 500 处理
     * @param \Throwable $exception
     * @param Response $response
     * @return Response
     */
    public function error500(\Throwable $exception, Response $response): Response
    {
        $content = [
            'message' => $e->getMessage(),
            'code'    => $e->getCode(),
        ];
        $body    = (new StreamFactory())->createStream(json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response
            ->withContentType('application/json', 'utf-8')
            ->withBody($body)
            ->withStatus(500);
    }

}