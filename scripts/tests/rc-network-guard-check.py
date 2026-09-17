#!/usr/bin/env python3
"""Run under rc-safe-run; reserved documentation addresses only."""
import errno
import socket

for kind in (socket.SOCK_STREAM, socket.SOCK_DGRAM):
    with socket.socket(socket.AF_INET, kind) as client:
        try:
            if kind == socket.SOCK_STREAM:
                client.connect(("203.0.113.1", 80))
            else:
                client.sendto(b"guard-probe", ("203.0.113.1", 53))
        except OSError as error:
            assert error.errno == errno.EACCES, error
        else:
            raise AssertionError("Non-loopback transport was not denied")

try:
    socket.getaddrinfo("203.0.113.1", 80)
except socket.gaierror as error:
    assert error.errno == socket.EAI_NONAME, error
else:
    raise AssertionError("Non-loopback resolver was not denied")

assert socket.getaddrinfo("127.0.0.1", 80, socket.AF_INET)
print("PASS: non-loopback TCP, UDP and resolution denied; loopback resolves")