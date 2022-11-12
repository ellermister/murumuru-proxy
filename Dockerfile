
FROM phpswoole/swoole:4.8-php7.4

ENV MINIFY_DIST "/dist/"

ENV WEB_ROOT "/app"
ENV CGI_WEB_ROOT "/app"
ENV CGI_URL '172.22.0.10:9000'
ENV HTTPS_ENABLED false
ENV DEBUG_OUTPUT true
ENV MINIMIZE_STATIC_RESOURCE true
ENV BASE64_INLINE_MAX_SIZE 30720
ENV LISTEN_PORT 80




RUN apt update \
    && apt install libaom-dev vim supervisor -y --no-install-recommends  \
    && ln -s /usr/lib/x86_64-linux-gnu/libaom.so /usr/lib/x86_64-linux-gnu/libaom.so.3 \
    && mkdir -p /var/log/supervisor


ADD https://github.com/webp-sh/webp_server_go/releases/download/0.4.5/webp-server-linux-amd64-80aa8cb63a85a986f83a88579e8d2b4b /muru-proxy/webp-server
RUN chmod +x /muru-proxy/webp-server


COPY proxy.php /muru-proxy/
COPY config.json /muru-proxy/
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf




WORKDIR /muru-proxy

CMD ["php","proxy.php"]