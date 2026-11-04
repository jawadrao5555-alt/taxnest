#!/usr/bin/env python3
"""Execute disposable SQLite test commands with network sockets denied for the process and its children."""
import ctypes
import ctypes.util
import errno
import os
import socket
import sys

def main():
    if len(sys.argv) < 2:
        raise SystemExit("Supply the test command to execute")
    lib = ctypes.CDLL(ctypes.util.find_library("seccomp") or "libseccomp.so.2")
    lib.seccomp_init.argtypes = [ctypes.c_uint32]
    lib.seccomp_init.restype = ctypes.c_void_p
    lib.seccomp_syscall_resolve_name.argtypes = [ctypes.c_char_p]
    lib.seccomp_rule_add.argtypes = [ctypes.c_void_p, ctypes.c_uint32, ctypes.c_int, ctypes.c_uint]
    lib.seccomp_load.argtypes = [ctypes.c_void_p]
    lib.seccomp_release.argtypes = [ctypes.c_void_p]
    ctx = lib.seccomp_init(0x7fff0000)  # SCMP_ACT_ALLOW
    if not ctx:
        raise SystemExit("Cannot initialize network restrictions")
    # Deny sockets, including datagrams, raw sockets and io_uring's alternative
    # socket interface. close inherited descriptors before launching child commands.
    for syscall in ("socket", "connect", "sendto", "sendmsg", "sendmmsg", "io_uring_setup"):
        number = lib.seccomp_syscall_resolve_name(syscall.encode())
        if number < 0 or lib.seccomp_rule_add(ctx, 0x00050000 | errno.EPERM, number, 0):
            raise SystemExit("Cannot install restriction for " + syscall)
    if lib.seccomp_load(ctx):
        raise SystemExit("Cannot activate network restrictions")
    lib.seccomp_release(ctx)
    for family in (socket.AF_INET, socket.AF_INET6):
        try:
            socket.socket(family).close()
        except PermissionError:
            continue
        raise SystemExit("Network restriction verification failed")
    os.closerange(3, os.sysconf("SC_OPEN_MAX"))
    print("Verified offline: sockets denied; inherited by test children.", flush=True)
    os.execvp(sys.argv[1], sys.argv[1:])

if __name__ == "__main__":
    main()
