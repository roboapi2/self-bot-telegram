# syntax=docker/dockerfile:1
# Stable Caddy source rebuilt with the October 2026 Go and HTTP/2 fixes.
FROM golang:1.26.9-alpine3.23@sha256:6750308561953d451210ed1a8086d36c8c8bdcbf672d38d9d0d424b8a7a0216c AS build
ENV GOTOOLCHAIN=local CGO_ENABLED=0 GOPROXY=https://proxy.golang.org GOSUMDB=sum.golang.org
WORKDIR /src
ADD --checksum=sha256:c5b3a3304999625d751b2d2284cb037b9530bf563d1a62b720a7aa6765b0a5d9 https://proxy.golang.org/github.com/caddyserver/caddy/v2/@v/v2.11.7.zip /tmp/caddy.zip
RUN unzip -q /tmp/caddy.zip -d /tmp/source && cp /tmp/source/github.com/caddyserver/caddy/v2@v2.11.7/cmd/caddy/main.go /src/main.go && rm /tmp/caddy.zip
COPY docker/gateway/go.mod docker/gateway/go.sum ./
RUN go mod download && go mod verify
RUN go build -mod=readonly -trimpath -buildvcs=false -ldflags='-s -w' -o /out/caddy . \
    && go version -m /out/caddy | grep 'go1.26.9' \
    && go version -m /out/caddy | grep 'golang.org/x/net.*v0.60.0'
# Only the trusted CA bundle is copied; the vulnerable upstream binary is excluded.
FROM caddy:2.11.7-alpine@sha256:d8542f48d34a9cf4e4c11a478865229840e87e4c96ea3f439101f31a5d35f75f AS certificates
FROM alpine:3.23@sha256:85fe1e81d6758c208f3e1eed4338a1997e19d4be002d4dd32d3100c9a8c010a0
ARG VCS_REF=unknown
LABEL org.opencontainers.image.source="https://github.com/roboapi2/self-bot-telegram" \
      org.opencontainers.image.revision="$VCS_REF" \
      org.opencontainers.image.version="2.11.7-go1.26.9-xnet0.60.0" \
      org.opencontainers.image.licenses="Apache-2.0"
ENV XDG_CONFIG_HOME=/config XDG_DATA_HOME=/data
COPY --from=certificates /etc/ssl/certs/ca-certificates.crt /etc/ssl/certs/ca-certificates.crt
COPY --from=build /out/caddy /usr/bin/caddy
COPY docker/Caddyfile /etc/caddy/Caddyfile
RUN mkdir -p /data/caddy /config/caddy && chown -R 10001:10001 /data /config
USER 10001:10001
WORKDIR /srv
EXPOSE 8080 8443
CMD ["caddy", "run", "--config", "/etc/caddy/Caddyfile", "--adapter", "caddyfile"]
