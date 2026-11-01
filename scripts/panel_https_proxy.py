#!/usr/bin/env python3
"""TLS terminator in front of the Yarbo PHP panel (plain HTTP upstream).

Keeps / on HTTPS for browsers (microphone / secure context) while the panel
can still serve Paper tablets on HTTP :8080.
"""

from __future__ import annotations

import argparse
import select
import socket
import ssl
import sys
import threading


def pipe(a: socket.socket, b: socket.socket) -> None:
    try:
        while True:
            readable, _, _ = select.select([a, b], [], [], 120)
            if not readable:
                continue
            for src in readable:
                dst = b if src is a else a
                try:
                    data = src.recv(65536)
                except OSError:
                    return
                if not data:
                    return
                try:
                    dst.sendall(data)
                except OSError:
                    return
    finally:
        for s in (a, b):
            try:
                s.shutdown(socket.SHUT_RDWR)
            except OSError:
                pass
            try:
                s.close()
            except OSError:
                pass


def accept_loop(
    listen_host: str,
    listen_port: int,
    upstream_host: str,
    upstream_port: int,
    cert: str,
    key: str,
) -> None:
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    # Browsers only need a cert to click through; keep compatibility wide.
    if hasattr(ssl, "TLSVersion"):
        context.minimum_version = ssl.TLSVersion.TLSv1_2
    context.load_cert_chain(certfile=cert, keyfile=key)

    listener = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    listener.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    listener.bind((listen_host, listen_port))
    listener.listen(64)
    print(
        f"panel_https_proxy: https://{listen_host}:{listen_port} → "
        f"http://{upstream_host}:{upstream_port}",
        flush=True,
    )

    while True:
        client, _addr = listener.accept()
        try:
            tls = context.wrap_socket(client, server_side=True)
        except ssl.SSLError:
            try:
                client.close()
            except OSError:
                pass
            continue
        try:
            upstream = socket.create_connection((upstream_host, upstream_port), timeout=10)
        except OSError as exc:
            print(f"panel_https_proxy: upstream connect failed: {exc}", file=sys.stderr, flush=True)
            try:
                tls.close()
            except OSError:
                pass
            continue
        threading.Thread(target=pipe, args=(tls, upstream), daemon=True).start()


def main() -> int:
    parser = argparse.ArgumentParser(description="Yarbo panel HTTPS reverse proxy")
    parser.add_argument("--listen-host", default="0.0.0.0")
    parser.add_argument("--listen-port", type=int, default=8443)
    parser.add_argument("--upstream-host", default="127.0.0.1")
    parser.add_argument("--upstream-port", type=int, default=8080)
    parser.add_argument("--cert", required=True)
    parser.add_argument("--key", required=True)
    args = parser.parse_args()
    try:
        accept_loop(
            args.listen_host,
            args.listen_port,
            args.upstream_host,
            args.upstream_port,
            args.cert,
            args.key,
        )
    except KeyboardInterrupt:
        return 0
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
