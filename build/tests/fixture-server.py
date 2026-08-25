#!/usr/bin/env python3
"""Serve the smoke-test fixtures by hand, for eyeballing the gate.

    tests/fixture-server.py <port> <good|bad>

There is exactly ONE definition of these fixtures and it lives in selftest.py,
which is the thing that actually runs in the gate. This file used to carry its
own copy; two fixture sets that must agree is the same drift risk as two
merchant normalizers or two net-worth definitions, and the copy had already
fallen a route behind before anyone noticed.
"""
import os
import sys
import importlib.util

if len(sys.argv) < 3 or sys.argv[2] not in ("good", "bad"):
    sys.exit(__doc__)

_spec = importlib.util.spec_from_file_location(
    "selftest_fixtures", os.path.join(os.path.dirname(os.path.abspath(__file__)), "selftest.py"))
_mod = importlib.util.module_from_spec(_spec)
# selftest.py runs its cases at import time. Only the fixture definitions are
# wanted here, so execute it far enough to get them and no further.
_src = open(_spec.origin).read().split("print(f\"Gate under test")[0]
exec(compile(_src, _spec.origin, "exec"), _mod.__dict__)

port = int(sys.argv[1])
srv = _mod.serve(sys.argv[2], port)
print(f"fixtures ({sys.argv[2]}) on http://127.0.0.1:{port}  — ctrl-c to stop")
try:
    import time
    while True:
        time.sleep(3600)
except KeyboardInterrupt:
    srv.shutdown()
