<div style="text-align:left"><img src="./myapp/assets/images/murumuru.png" ></div>



## Muru-Proxy

一个优化页面速度的代理服务器

通过增加中间件的形式，把返回的 HTML 页面进行处理优化再响应给用户浏览器。



### 工作流程

**Nginx** <- proxy_pass -> **muru-proxy** <- fcgi_pass -> **php-fpm**



### 优化内容

- 提取 HTML 页面中所有站内 CSS, JS 的链接为一个文件
- 优化 CSS 文件中图标、字体等小体积资源文件为内联嵌入
- 简单合并压缩 CSS 文件 换行空格等不必要字符
- 替换HTML页面、CSS文件所有站内全路径链接为根路径链接
- 请求站点图片时自动压缩并转换为 WebP 格式



### 注意事项

- `muru-porxy` 并不会处理纯静态的 html 文件
- 默认开启伪静态
- 需要创建 dist 目录用于缓存文件存储
- 只适用于 php-fpm 项目
- 可能影响 JavaScript 的加载顺序



### 手动清理缓存

请求地址即可清理所有缓存文件 `/muru-cgi/clear`



## Docker

使用 Docker 快速配置, 参考 `docker-compose.yml`

```bash
docker-compose pull
docker-compose up -d
```



### 参数说明

如下为主要配置说明，结合 `docker-compose.yml` 文件中的例子配置进行理解。
- WEB_ROOT 和 CGI_WEB_ROOT 有可能一样，有可能地址不一样，取决于你如何配置 php-fpm。


```yaml
    environment:
      WEB_ROOT: "/app" # 容器内网站目录,不变
      CGI_WEB_ROOT: "/app/default.com/public" # CGI 得到的网站目录，取决于你 php-fpm 配置在哪里
      CGI_URL: '172.22.0.10:9000' # or unix:php.socket # CGI通信地址
      HTTPS_ENABLED: false # 站点是否开启HTTPS
      DEBUG_OUTPUT: true # 是否显示调试日志
      BASE64_INLINE_MAX_SIZE: 30720 # 图片 base64为行内元素的最大体积 Bytes
      LISTEN_PORT: 80 #监听端口
      MINIMIZE_STATIC_RESOURCE: true  # 是否压缩静态资源
```




## Nginx

启动后服务在容器的  `172.22.0.12` 运行,可以从宿主机进行代理访问，在容器网络内也可以通过容器名访问。

```nginx
server{
    listen 80;
    server_name localhost;

    index index.html index.htm index.php;
    root  /app;

    gzip on;
    gzip_min_length 1k;
    gzip_comp_level 8;
    gzip_types text/plain application/javascript application/x-javascript text/css application/xml text/javascript application/x-httpd-php image/jpeg image/gif image/png application/vnd.ms-fontobject font/ttf font/opentype font/x-woff image/svg+xml;
    gzip_vary on;
    gzip_buffers 32 4k;

    location / {
        proxy_pass       http://muru-proxy:80;
        proxy_set_header Host      $host;
        proxy_set_header X-Real-IP $remote_addr;
    }
    
}
```

若只需要代理部分页面请求，如 WordPress 反向代理前台页面：

```nginx
server{
    listen 80;
    server_name localhost;

    index index.html index.htm index.php;
    root  /app;

	# 管理员页面
    location /wp-admin {
		try_files $uri $uri/ /index.php?$args;
	}

	# 静态资源页
    location ^~ /wp-content {
        proxy_pass http://172.22.0.12:80;
        proxy_set_header Host $host;
    }


	# 默认请求页
    location  / {
      proxy_pass http://172.22.0.12:80;
      proxy_set_header Host $host;
    }

    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_pass unix:/run/php/php8.0-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
    
}
```

## 引用项目

[webp_server_go](https://github.com/webp-sh/webp_server_go)



